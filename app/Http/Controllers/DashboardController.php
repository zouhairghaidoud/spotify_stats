<?php

namespace App\Http\Controllers;

use App\Services\SpotifyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with either the connect prompt or live Spotify stats.
     */
    public function __invoke(Request $request, SpotifyService $spotify): Response
    {
        $user = $request->user();

        if (! $user->isConnectedToSpotify()) {
            return Inertia::render('Dashboard', [
                'connected' => false,
            ]);
        }

        $range = $request->query('range', 'short_term');

        if (! is_string($range) || ! in_array($range, ['short_term', 'medium_term', 'long_term'], true)) {
            $range = 'short_term';
        }

        $data = [
            'connected' => true,
            'range' => $range,
            'spotifyError' => null,
            'currentlyPlaying' => null,
            'topTracks' => [],
            'topArtists' => [],
            'recentlyPlayed' => [],
        ];

        try {
            $data['currentlyPlaying'] = $spotify->getCurrentlyPlaying($user);
            $data['topTracks'] = $spotify->getTopTracks($user, $range)['items'] ?? [];
            $data['topArtists'] = $spotify->getTopArtists($user, $range)['items'] ?? [];
            $data['recentlyPlayed'] = $spotify->getRecentlyPlayed($user)['items'] ?? [];
        } catch (Throwable $exception) {
            report($exception);

            $data['spotifyError'] = 'We could not load your Spotify data. Please reconnect your account and try again.';
        }

        return Inertia::render('Dashboard', $data);
    }
}
