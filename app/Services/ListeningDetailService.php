<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Builds detail pages for a single album or artist from stored plays.
 */
class ListeningDetailService
{
    private const TRACK_LIMIT = 20;

    private const ALBUM_LIMIT = 12;

    private const TIMELINE_MONTHS = 12;

    /**
     * @return array{
     *     album_name: string,
     *     artist_name: string|null,
     *     cover_url: string|null,
     *     overview: array{plays: int, minutes: int, unique_tracks: int, first_played_at: string|null, last_played_at: string|null},
     *     timeline: array<int, array{month: string, plays: int}>,
     *     tracks: array<int, array{spotify_track_id: string, name: string, plays: int, minutes: int, album_image_url: string|null}>
     * }|null
     */
    public function album(User $user, string $albumName, ?string $artistName = null): ?array
    {
        $albumName = trim($albumName);

        if ($albumName === '') {
            return null;
        }

        $query = $this->albumStreams($user, $albumName, $artistName);

        $overview = (clone $query)
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('COALESCE(SUM(COALESCE(streams.duration_ms, tracks.duration_ms, 0)), 0) as duration_ms')
            ->selectRaw('COUNT(DISTINCT streams.spotify_track_id) as tracks')
            ->selectRaw('MIN(streams.played_at) as first_played_at')
            ->selectRaw('MAX(streams.played_at) as last_played_at')
            ->selectRaw('MAX(tracks.album_image_url) as cover_url')
            ->selectRaw('MAX(tracks.artist_name) as artist_name')
            ->toBase()
            ->first();

        if ((int) ($overview->plays ?? 0) === 0) {
            return null;
        }

        $tracks = (clone $query)
            ->selectRaw('streams.spotify_track_id as spotify_track_id')
            ->selectRaw("COALESCE(tracks.name, 'Unknown track') as name")
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('ROUND(COALESCE(SUM(COALESCE(streams.duration_ms, tracks.duration_ms, 0)), 0) / 60000.0) as minutes')
            ->selectRaw('MAX(tracks.album_image_url) as album_image_url')
            ->groupBy('streams.spotify_track_id', 'tracks.name')
            ->orderByDesc('plays')
            ->orderBy('streams.spotify_track_id')
            ->limit(self::TRACK_LIMIT)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'spotify_track_id' => (string) $row->spotify_track_id,
                'name' => (string) $row->name,
                'plays' => (int) $row->plays,
                'minutes' => (int) $row->minutes,
                'album_image_url' => filled($row->album_image_url) ? (string) $row->album_image_url : null,
            ])
            ->all();

        return [
            'album_name' => $albumName,
            'artist_name' => filled($overview->artist_name ?? null) ? (string) $overview->artist_name : null,
            'cover_url' => filled($overview->cover_url ?? null) ? (string) $overview->cover_url : null,
            'overview' => [
                'plays' => (int) $overview->plays,
                'minutes' => intdiv((int) $overview->duration_ms, 60000),
                'unique_tracks' => (int) $overview->tracks,
                'first_played_at' => $this->timestamp($overview->first_played_at),
                'last_played_at' => $this->timestamp($overview->last_played_at),
            ],
            'timeline' => $this->timeline($query),
            'tracks' => $tracks,
        ];
    }

    /**
     * @return array{
     *     artist_id: string,
     *     name: string,
     *     genres: array<int, string>,
     *     overview: array{plays: int, minutes: int, unique_tracks: int, unique_albums: int, first_played_at: string|null, last_played_at: string|null},
     *     timeline: array<int, array{month: string, plays: int}>,
     *     tracks: array<int, array{spotify_track_id: string, name: string, album_name: string|null, plays: int, minutes: int, album_image_url: string|null}>,
     *     albums: array<int, array{album_name: string, artist_name: string|null, cover_url: string|null, plays: int, minutes: int, unique_tracks_played: int}>
     * }|null
     */
    public function artist(User $user, string $artistId): ?array
    {
        $artistId = trim($artistId);

        if ($artistId === '') {
            return null;
        }

        $query = $user->streams()
            ->where('streams.artist_id', $artistId)
            ->leftJoin('tracks', 'tracks.spotify_track_id', '=', 'streams.spotify_track_id');

        $overview = (clone $query)
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('COALESCE(SUM(COALESCE(streams.duration_ms, tracks.duration_ms, 0)), 0) as duration_ms')
            ->selectRaw('COUNT(DISTINCT streams.spotify_track_id) as tracks')
            ->selectRaw("COUNT(DISTINCT NULLIF(tracks.album_name, '')) as albums")
            ->selectRaw('MIN(streams.played_at) as first_played_at')
            ->selectRaw('MAX(streams.played_at) as last_played_at')
            ->toBase()
            ->first();

        if ((int) ($overview->plays ?? 0) === 0) {
            return null;
        }

        $artist = Artist::query()
            ->where('spotify_artist_id', $artistId)
            ->first();

        $tracks = (clone $query)
            ->selectRaw('streams.spotify_track_id as spotify_track_id')
            ->selectRaw("COALESCE(tracks.name, 'Unknown track') as name")
            ->selectRaw('tracks.album_name as album_name')
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('ROUND(COALESCE(SUM(COALESCE(streams.duration_ms, tracks.duration_ms, 0)), 0) / 60000.0) as minutes')
            ->selectRaw('MAX(tracks.album_image_url) as album_image_url')
            ->groupBy('streams.spotify_track_id', 'tracks.name', 'tracks.album_name')
            ->orderByDesc('plays')
            ->orderBy('streams.spotify_track_id')
            ->limit(self::TRACK_LIMIT)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'spotify_track_id' => (string) $row->spotify_track_id,
                'name' => (string) $row->name,
                'album_name' => filled($row->album_name) ? (string) $row->album_name : null,
                'plays' => (int) $row->plays,
                'minutes' => (int) $row->minutes,
                'album_image_url' => filled($row->album_image_url) ? (string) $row->album_image_url : null,
            ])
            ->all();

        $albums = (clone $query)
            ->whereNotNull('tracks.album_name')
            ->where('tracks.album_name', '!=', '')
            ->selectRaw('tracks.album_name as album_name')
            ->selectRaw('MAX(tracks.artist_name) as artist_name')
            ->selectRaw('MAX(tracks.album_image_url) as cover_url')
            ->selectRaw('COUNT(streams.id) as plays')
            ->selectRaw('COALESCE(SUM(COALESCE(streams.duration_ms, tracks.duration_ms, 0)), 0) as duration_ms')
            ->selectRaw('COUNT(DISTINCT streams.spotify_track_id) as unique_tracks_played')
            ->groupBy('tracks.album_name')
            ->orderByDesc('plays')
            ->orderByDesc('duration_ms')
            ->orderBy('tracks.album_name')
            ->limit(self::ALBUM_LIMIT)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'album_name' => (string) $row->album_name,
                'artist_name' => filled($row->artist_name) ? (string) $row->artist_name : null,
                'cover_url' => filled($row->cover_url) ? (string) $row->cover_url : null,
                'plays' => (int) $row->plays,
                'minutes' => (int) round((int) $row->duration_ms / 60000),
                'unique_tracks_played' => (int) $row->unique_tracks_played,
            ])
            ->all();

        $name = $artist?->name;

        if (! filled($name)) {
            $name = $albums[0]['artist_name'] ?? 'Unknown artist';
        }

        return [
            'artist_id' => $artistId,
            'name' => (string) $name,
            'genres' => $artist?->genres ?? [],
            'overview' => [
                'plays' => (int) $overview->plays,
                'minutes' => intdiv((int) $overview->duration_ms, 60000),
                'unique_tracks' => (int) $overview->tracks,
                'unique_albums' => (int) $overview->albums,
                'first_played_at' => $this->timestamp($overview->first_played_at),
                'last_played_at' => $this->timestamp($overview->last_played_at),
            ],
            'timeline' => $this->timeline($query),
            'tracks' => $tracks,
            'albums' => $albums,
        ];
    }

    /**
     * @return Builder<Stream>|HasMany<Stream, User>
     */
    private function albumStreams(User $user, string $albumName, ?string $artistName): Builder|HasMany
    {
        return $user->streams()
            ->join('tracks', 'tracks.spotify_track_id', '=', 'streams.spotify_track_id')
            ->where('tracks.album_name', $albumName)
            ->when(filled($artistName), fn ($query) => $query->where('tracks.artist_name', $artistName));
    }

    /**
     * @param  Builder<Stream>|HasMany<Stream, User>  $query
     * @return array<int, array{month: string, plays: int}>
     */
    private function timeline(Builder|HasMany $query): array
    {
        $counts = [];

        (clone $query)
            ->select(['streams.played_at'])
            ->orderBy('streams.played_at')
            ->toBase()
            ->cursor()
            ->each(function (object $row) use (&$counts): void {
                $month = Carbon::parse($row->played_at)->format('Y-m');
                $counts[$month] = ($counts[$month] ?? 0) + 1;
            });

        ksort($counts);

        return collect(array_slice($counts, -self::TIMELINE_MONTHS, null, true))
            ->map(fn (int $plays, string $month): array => ['month' => $month, 'plays' => $plays])
            ->values()
            ->all();
    }

    private function timestamp(mixed $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->toIso8601String() : null;
    }
}
