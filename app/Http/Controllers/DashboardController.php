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
            'imports' => $imports->recent($user),
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
            'topAlbums' => [],
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

        $data['topAlbums'] = $this->topAlbums($data['topTracks']);

        return Inertia::render('Dashboard', $data);
    }

    /**
     * Derive albums from the cached top tracks.
     *
     * Spotify has no top-albums endpoint, so albums are ranked by the inverse
     * rank of every top track that belongs to them. A track at position #1 is
     * worth more than the same track at position #50.
     *
     * @param  array<int, array<string, mixed>>  $tracks
     * @return array<int, array{id: string|null, name: string, artist_name: string|null, cover_url: string|null, url: string|null, track_count: int, score: int, top_tracks: array<int, array{id: string|null, name: string}>}>
     */
    private function topAlbums(array $tracks): array
    {
        $albums = [];

        foreach (array_values($tracks) as $index => $track) {
            $album = $track['album'] ?? null;

            if (! is_array($album)) {
                continue;
            }

            $name = trim((string) ($album['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $key = (string) ($album['id'] ?? mb_strtolower($name));

            $albums[$key] ??= [
                'id' => filled($album['id'] ?? null) ? (string) $album['id'] : null,
                'name' => $name,
                'artist_name' => filled(data_get($album, 'artists.0.name'))
                    ? (string) data_get($album, 'artists.0.name')
                    : null,
                'cover_url' => filled(data_get($album, 'images.0.url'))
                    ? (string) data_get($album, 'images.0.url')
                    : null,
                'url' => filled(data_get($album, 'external_urls.spotify'))
                    ? (string) data_get($album, 'external_urls.spotify')
                    : null,
                'track_count' => 0,
                'score' => 0,
                'top_tracks' => [],
            ];

            $albums[$key]['track_count']++;
            $albums[$key]['score'] += max(1, 51 - $index);

            if (filled($track['name'] ?? null)) {
                $albums[$key]['top_tracks'][] = [
                    'id' => filled($track['id'] ?? null) ? (string) $track['id'] : null,
                    'name' => (string) $track['name'],
                ];
            }
        }

        usort($albums, function (array $left, array $right): int {
            return $right['score'] <=> $left['score']
                ?: $right['track_count'] <=> $left['track_count']
                ?: strcasecmp($left['name'], $right['name']);
        });

        return array_slice($albums, 0, 12);
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
