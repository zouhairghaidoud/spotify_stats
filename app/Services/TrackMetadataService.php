<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Stream;
use App\Models\Track;
use App\Models\User;
use Throwable;

/**
 * Backfills track covers and artist ids for imported plays.
 *
 * The Client Credentials flow used here is not limited by Spotify's
 * Development Mode user allowlist. The `/v1/tracks` endpoint is tried first
 * in batches of 50, then oEmbed per track for anything unresolved.
 */
class TrackMetadataService
{
    /**
     * How many of a user's most played tracks are enriched per import.
     */
    public const LIMIT = 200;

    /**
     * Cap oEmbed fallbacks so a large import cannot issue hundreds of requests.
     */
    private const OEMBED_LIMIT = 25;

    public function __construct(private readonly SpotifyService $spotify) {}

    /**
     * Enrich missing covers and artist ids for the user's top imported tracks.
     *
     * @return int the number of tracks updated
     */
    public function enrich(User $user, int $limit = self::LIMIT): int
    {
        $trackIds = $this->candidates($user, $limit);

        if ($trackIds === []) {
            return 0;
        }

        $spotifyIds = array_values(array_filter(
            $trackIds,
            fn (string $id): bool => ! str_starts_with($id, 'local_'),
        ));

        $updated = 0;
        $resolved = [];

        if ($spotifyIds !== []) {
            try {
                $lookup = $this->spotify->lookupTracks($spotifyIds);
                $resolved = array_keys($lookup);
                $updated += $this->applySpotifyTracks($user, $lookup);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $unresolved = array_values(array_diff($spotifyIds, $resolved));

        if ($unresolved !== []) {
            $updated += $this->applyOembedFallback(
                $user,
                array_slice($unresolved, 0, self::OEMBED_LIMIT),
            );
        }

        return $updated;
    }

    /**
     * The user's most played tracks that still miss a cover or artist id.
     *
     * @return list<string>
     */
    private function candidates(User $user, int $limit): array
    {
        return $user->streams()
            ->join('tracks', 'tracks.spotify_track_id', '=', 'streams.spotify_track_id')
            ->where(function ($query): void {
                $query->whereNull('tracks.album_image_url')
                    ->orWhereNull('tracks.artist_id');
            })
            ->selectRaw('streams.spotify_track_id as track_id, COUNT(*) as plays')
            ->groupBy('streams.spotify_track_id')
            ->orderByDesc('plays')
            ->limit($limit)
            ->toBase()
            ->get()
            ->pluck('track_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $lookup
     */
    private function applySpotifyTracks(User $user, array $lookup): int
    {
        if ($lookup === []) {
            return 0;
        }

        $now = now();
        $trackRows = [];
        $artistRows = [];
        $pairs = [];

        foreach ($lookup as $id => $track) {
            $artistId = data_get($track, 'artists.0.id');
            $artistName = data_get($track, 'artists.0.name');

            $trackRows[] = [
                'spotify_track_id' => (string) $id,
                'name' => (string) ($track['name'] ?? 'Unknown track'),
                'artist_id' => filled($artistId) ? (string) $artistId : null,
                'artist_name' => filled($artistName) ? (string) $artistName : null,
                'album_name' => filled(data_get($track, 'album.name'))
                    ? (string) data_get($track, 'album.name')
                    : null,
                'album_image_url' => filled(data_get($track, 'album.images.0.url'))
                    ? (string) data_get($track, 'album.images.0.url')
                    : null,
                'duration_ms' => is_numeric($track['duration_ms'] ?? null)
                    ? (int) $track['duration_ms']
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (filled($artistId) && filled($artistName)) {
                $artistRows[(string) $artistId] = [
                    'spotify_artist_id' => (string) $artistId,
                    'name' => (string) $artistName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (filled($artistId)) {
                $pairs[(string) $id] = (string) $artistId;
            }
        }

        $this->upsertTracks($trackRows, includeArtist: true);
        $this->upsertArtists(array_values($artistRows));
        $this->assignStreamArtists($user, $pairs);

        return count($trackRows);
    }

    /**
     * @param  list<string>  $trackIds
     */
    private function applyOembedFallback(User $user, array $trackIds): int
    {
        $now = now();
        $trackRows = [];
        $artistRows = [];
        $pairs = [];

        foreach ($trackIds as $trackId) {
            $metadata = $this->spotify->lookupTrackViaOembed($trackId);

            if (! is_array($metadata)) {
                continue;
            }

            $artistName = filled($metadata['author_name'] ?? null)
                ? (string) $metadata['author_name']
                : null;
            $artistId = filled($metadata['artist_id'] ?? null)
                ? (string) $metadata['artist_id']
                : $this->localArtistId($artistName);

            $trackRows[] = [
                'spotify_track_id' => $trackId,
                'name' => (string) ($metadata['title'] ?? 'Unknown track'),
                'artist_id' => $artistId,
                'artist_name' => $artistName,
                'album_name' => null,
                'album_image_url' => filled($metadata['thumbnail_url'] ?? null)
                    ? (string) $metadata['thumbnail_url']
                    : null,
                'duration_ms' => is_numeric($metadata['duration_ms'] ?? null)
                    ? (int) $metadata['duration_ms']
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($artistId !== null && $artistName !== null) {
                $artistRows[$artistId] = [
                    'spotify_artist_id' => $artistId,
                    'name' => $artistName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $pairs[$trackId] = $artistId;
            }
        }

        $this->upsertTracks($trackRows, includeArtist: true);
        $this->upsertArtists(array_values($artistRows));
        $this->assignStreamArtists($user, $pairs);

        return count($trackRows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertTracks(array $rows, bool $includeArtist): void
    {
        if ($rows === []) {
            return;
        }

        $update = ['name', 'artist_name', 'album_name', 'album_image_url', 'duration_ms', 'updated_at'];

        if ($includeArtist) {
            $update[] = 'artist_id';
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Track::query()->upsert($chunk, ['spotify_track_id'], $update);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertArtists(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Artist::query()->upsert($chunk, ['spotify_artist_id'], ['name', 'updated_at']);
        }
    }

    /**
     * Point imported plays at the richer artist id without touching known ids.
     *
     * @param  array<string, string>  $pairs
     */
    private function assignStreamArtists(User $user, array $pairs): void
    {
        if ($pairs === []) {
            return;
        }

        $byArtist = [];

        foreach ($pairs as $trackId => $artistId) {
            $byArtist[$artistId][] = $trackId;
        }

        foreach ($byArtist as $artistId => $trackIds) {
            foreach (array_chunk($trackIds, 500) as $chunk) {
                Stream::query()
                    ->where('user_id', $user->id)
                    ->whereIn('spotify_track_id', $chunk)
                    ->where(function ($query): void {
                        $query->whereNull('artist_id')
                            ->orWhere('artist_id', 'like', 'local_artist_%');
                    })
                    ->update(['artist_id' => $artistId, 'updated_at' => now()]);
            }
        }
    }

    private function localArtistId(?string $artistName): ?string
    {
        if ($artistName === null) {
            return null;
        }

        return 'local_artist_'.substr(md5(strtolower(trim($artistName))), 0, 18);
    }
}
