<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SpotifyCacheService
{
    /**
     * Seconds a cached top tracks/artists response is considered fresh.
     */
    private const FRESH_SECONDS = 900;

    /**
     * Seconds a cached recently played response is considered fresh.
     */
    private const RECENT_SECONDS = 300;

    /**
     * Seconds cached data is kept around to survive Spotify outages.
     */
    private const STALE_SECONDS = 604800;

    public function __construct(private readonly SpotifyService $spotify) {}

    /**
     * @return array{items: array<int, mixed>, stale: bool, cached_at: string|null}
     */
    public function topTracks(User $user, string $range): array
    {
        return $this->remember(
            "spotify:user:{$user->id}:top-tracks:{$range}",
            self::FRESH_SECONDS,
            fn (): array => $this->spotify->getTopTracks($user, $range)['items'] ?? [],
        );
    }

    /**
     * @return array{items: array<int, mixed>, stale: bool, cached_at: string|null}
     */
    public function topArtists(User $user, string $range): array
    {
        return $this->remember(
            "spotify:user:{$user->id}:top-artists:{$range}",
            self::FRESH_SECONDS,
            fn (): array => $this->spotify->getTopArtists($user, $range)['items'] ?? [],
        );
    }

    /**
     * @return array{items: array<int, mixed>, stale: bool, cached_at: string|null}
     */
    public function recentlyPlayed(User $user): array
    {
        return $this->remember(
            "spotify:user:{$user->id}:recently-played",
            self::RECENT_SECONDS,
            fn (): array => $this->spotify->getRecentlyPlayed($user)['items'] ?? [],
        );
    }

    /**
     * Return cached data when it is still fresh, otherwise fetch it.
     *
     * When Spotify fails and stale data exists, the stale data is returned
     * instead of throwing so the dashboard keeps working during outages.
     *
     * @param  Closure(): array<int, mixed>  $callback
     * @return array{items: array<int, mixed>, stale: bool, cached_at: string|null}
     */
    private function remember(string $key, int $freshSeconds, Closure $callback): array
    {
        $cached = Cache::get($key);
        $cached = is_array($cached) && isset($cached['items'], $cached['cached_at']) ? $cached : null;

        if ($cached !== null) {
            $cachedAt = Carbon::parse($cached['cached_at']);

            if ($cachedAt->copy()->addSeconds($freshSeconds)->greaterThan(now())) {
                return [
                    'items' => $cached['items'],
                    'stale' => false,
                    'cached_at' => $cached['cached_at'],
                ];
            }
        }

        try {
            $payload = [
                'items' => $callback(),
                'cached_at' => now()->toIso8601String(),
            ];

            Cache::put($key, $payload, self::STALE_SECONDS);

            return [
                'items' => $payload['items'],
                'stale' => false,
                'cached_at' => $payload['cached_at'],
            ];
        } catch (Throwable $exception) {
            if ($cached === null) {
                throw $exception;
            }

            report($exception);

            return [
                'items' => $cached['items'],
                'stale' => true,
                'cached_at' => $cached['cached_at'],
            ];
        }
    }
}
