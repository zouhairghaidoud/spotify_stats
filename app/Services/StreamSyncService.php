<?php

namespace App\Services;

use App\Models\Stream;
use App\Models\User;
use Illuminate\Support\Carbon;

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
                'played_at' => Carbon::parse($item['played_at'])->utc()->format('Y-m-d H:i:s.v'),
                'context' => data_get($item, 'context.type'),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->unique(fn (array $row): string => $row['spotify_track_id'].'|'.$row['played_at'])
            ->values()
            ->all();

        if ($rows === []) {
            return 0;
        }

        return Stream::query()->insertOrIgnore($rows);
    }
}
