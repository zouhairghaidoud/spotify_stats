<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class StreamSyncService
{
    public function __construct(private readonly SpotifyCacheService $spotify) {}

    /**
     * Persist the user's recently played tracks, ignoring plays already stored.
     *
     * The recently played payload is read through the cache so a dashboard
     * visit does not fetch the same data twice.
     *
     * @return int the number of newly stored plays
     */
    public function sync(User $user): int
    {
        $items = $this->spotify->recentlyPlayed($user)['items'];
        $now = now();

        $rows = collect($items)
            ->filter(fn (mixed $item): bool => is_array($item)
                && filled(data_get($item, 'track.id'))
                && filled($item['played_at'] ?? null))
            ->map(fn (array $item): array => [
                'user_id' => $user->id,
                'spotify_track_id' => (string) data_get($item, 'track.id'),
                'duration_ms' => is_numeric(data_get($item, 'track.duration_ms'))
                    ? (int) data_get($item, 'track.duration_ms')
                    : null,
                'artist_id' => filled(data_get($item, 'track.artists.0.id'))
                    ? (string) data_get($item, 'track.artists.0.id')
                    : null,
                'played_at' => Carbon::parse($item['played_at'])->utc()->format('Y-m-d H:i:s.v'),
                'context' => data_get($item, 'context.type'),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->unique(fn (array $row): string => $row['spotify_track_id'].'|'.$row['played_at'])
            ->values()
            ->all();

        $stored = $rows === [] ? 0 : Stream::query()->insertOrIgnore($rows);

        if ($rows !== []) {
            $this->backfillMetadata($user, $rows);
        }

        $this->snapshotArtists($user);

        return $stored;
    }

    /**
     * Fill in duration and artist for plays stored before those columns existed.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function backfillMetadata(User $user, array $rows): void
    {
        $hasGaps = Stream::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('duration_ms')->orWhereNull('artist_id'))
            ->exists();

        if (! $hasGaps) {
            return;
        }

        foreach ($rows as $row) {
            if ($row['duration_ms'] === null && $row['artist_id'] === null) {
                continue;
            }

            $playedAt = Carbon::parse($row['played_at']);

            Stream::query()
                ->where('user_id', $user->id)
                ->where('spotify_track_id', $row['spotify_track_id'])
                ->where('played_at', '>=', $playedAt->copy()->startOfSecond()->format('Y-m-d H:i:s'))
                ->where('played_at', '<=', $playedAt->copy()->endOfSecond()->format('Y-m-d H:i:s.u'))
                ->where(fn ($query) => $query->whereNull('duration_ms')->orWhereNull('artist_id'))
                ->update([
                    'duration_ms' => $row['duration_ms'],
                    'artist_id' => $row['artist_id'],
                    'updated_at' => $row['updated_at'],
                ]);
        }
    }

    /**
     * Snapshot artist genres so analytics never hit the Spotify API on load.
     *
     * Genres come from the top artists endpoint because the catalog artist
     * endpoints return 403 for apps created after November 2024. The snapshot
     * is refreshed once a day, which is plenty for genre data.
     */
    private function snapshotArtists(User $user): void
    {
        $cacheKey = "spotify:user:{$user->id}:artists-snapshot";

        if (Cache::has($cacheKey)) {
            return;
        }

        try {
            $artists = [];

            foreach (['short_term', 'medium_term', 'long_term'] as $range) {
                foreach ($this->spotify->topArtists($user, $range)['items'] as $artist) {
                    if (! is_array($artist) || blank($artist['id'] ?? null)) {
                        continue;
                    }

                    $artists[(string) $artist['id']] = [
                        'name' => (string) ($artist['name'] ?? ''),
                        'genres' => array_values(array_filter((array) ($artist['genres'] ?? []), 'is_string')),
                    ];
                }
            }

            if ($artists === []) {
                Cache::put($cacheKey, true, now()->addDay());

                return;
            }

            $now = now();
            $rows = [];

            foreach ($artists as $id => $artist) {
                $rows[] = [
                    'spotify_artist_id' => $id,
                    'name' => $artist['name'],
                    'genres' => json_encode($artist['genres']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            Artist::query()->upsert($rows, ['spotify_artist_id'], ['name', 'genres', 'updated_at']);

            Cache::put($cacheKey, true, now()->addDay());
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
