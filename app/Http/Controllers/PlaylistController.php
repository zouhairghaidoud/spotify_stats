<?php

namespace App\Http\Controllers;

use App\Services\PlaylistService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PlaylistController extends Controller
{
    /**
     * Show the playlist tools and the forgotten favorites list.
     */
    public function index(Request $request, PlaylistService $playlists): Response
    {
        $user = $request->user();

        return Inertia::render('Playlists', [
            'connected' => $user->isConnectedToSpotify(),
            'canCreate' => $user->hasPlaylistScope(),
            'ranges' => PlaylistService::RANGES,
            'topTrackLimit' => PlaylistService::TOP_TRACKS,
            'forgotten' => $playlists->forgottenFavorites($user),
            'forgottenDays' => PlaylistService::FORGOTTEN_DAYS,
            'forgottenMinPlays' => PlaylistService::FORGOTTEN_MIN_PLAYS,
            'createdPlaylist' => $request->session()->get('created_playlist'),
        ]);
    }

    /**
     * Create a private playlist from the user's current top tracks.
     */
    public function topTracks(Request $request, PlaylistService $playlists): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPlaylistScope()) {
            return $this->missingScope();
        }

        $range = $request->input('range', 'short_term');

        return $this->create(
            fn (): array => $playlists->createFromTopTracks(
                $user,
                is_string($range) ? $range : 'short_term',
            ),
        );
    }

    /**
     * Create a private playlist from forgotten favorites.
     */
    public function forgotten(Request $request, PlaylistService $playlists): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPlaylistScope()) {
            return $this->missingScope();
        }

        return $this->create(fn (): array => $playlists->createFromForgottenFavorites($user));
    }

    /**
     * Run a playlist creation and flash the result for the next page load.
     *
     * @param  callable(): array{id: string, name: string, url: string, track_count: int}  $callback
     */
    private function create(callable $callback): RedirectResponse
    {
        try {
            $playlist = $callback();
        } catch (Throwable $exception) {
            report($exception);

            return to_route('playlists.index')->with('error', $this->failureMessage($exception));
        }

        return to_route('playlists.index')
            ->with('success', 'Playlist created in your Spotify library.')
            ->with('created_playlist', $playlist);
    }

    private function missingScope(): RedirectResponse
    {
        return to_route('playlists.index')
            ->with('error', 'Reconnect Spotify to allow creating playlists.');
    }

    private function failureMessage(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            $status = $exception->response?->status();

            if ($status === 403) {
                return 'Spotify refused the playlist request. Reconnect your account to grant playlist access.';
            }

            if ($status === 429) {
                return 'Spotify is rate limiting playlist creation. Please try again in a minute.';
            }
        }

        return 'Could not create the playlist. Please try again.';
    }
}
