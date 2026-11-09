<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ProvidesSpotifyApp;
use App\Models\User;
use App\Services\HistoryImportService;
use App\Services\SpotifyCacheService;
use App\Services\SpotifyService;
use App\Services\StreamSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
    use ProvidesSpotifyApp;

    /**
     * Display the dashboard with either the connect prompt or live Spotify stats.
     */
    public function __invoke(
        Request $request,
        SpotifyService $spotify,
        SpotifyCacheService $cache,
        StreamSyncService $streams,
        HistoryImportService $imports,
    ): Response {
        $user = $request->user();

        $shared = [
            'spotifyApp' => $this->spotifyAppProps($user),
            'importSummary' => $imports->summary($user),
        ];

        if (! $user->isConnectedToSpotify()) {
            return Inertia::render('Dashboard', [
                'connected' => false,
                ...$shared,
            ]);
        }

        $range = $request->query('range', 'short_term');

        if (! is_string($range) || ! in_array($range, ['short_term', 'medium_term', 'long_term'], true)) {
            $range = 'short_term';
        }

        $this->persistListeningHistory($streams, $user);

        $data = [
            'connected' => true,
            'range' => $range,
            'spotifyError' => null,
            'spotifyStale' => false,
            'currentlyPlaying' => null,
            'topTracks' => [],
            'topArtists' => [],
            'recentlyPlayed' => [],
            ...$shared,
        ];

        $resources = [
            'topTracks' => fn (): array => $cache->topTracks($user, $range),
            'topArtists' => fn (): array => $cache->topArtists($user, $range),
            'recentlyPlayed' => fn (): array => $cache->recentlyPlayed($user),
        ];

        foreach ($resources as $prop => $load) {
            try {
                $result = $load();

                $data[$prop] = $result['items'];
                $data['spotifyStale'] = $data['spotifyStale'] || $result['stale'];
            } catch (Throwable $exception) {
                report($exception);

                $data['spotifyError'] = 'We could not load your Spotify data. Please reconnect your account and try again.';
            }
        }

        try {
            $data['currentlyPlaying'] = $spotify->getCurrentlyPlaying($user);
        } catch (Throwable $exception) {
            report($exception);
        }

        return Inertia::render('Dashboard', $data);
    }

    /**
     * Keep the listening history fresh without depending on the scheduler.
     *
     * The sync is skipped when a play was stored in the last fifteen minutes
     * and is throttled per user so page loads never hammer the Spotify API.
     * A failure here must never prevent the dashboard from rendering.
     */
    private function persistListeningHistory(StreamSyncService $streams, User $user): void
    {
        $lastPlayedAt = $user->streams()->max('played_at');

        if ($lastPlayedAt !== null && Carbon::parse($lastPlayedAt)->greaterThan(now()->subMinutes(15))) {
            return;
        }

        if (! Cache::add("spotify:user:{$user->id}:stream-sync", true, now()->addMinutes(5))) {
            return;
        }

        try {
            $streams->sync($user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
