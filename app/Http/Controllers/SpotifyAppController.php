<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SpotifyAppController extends Controller
{
    /**
     * Save the authenticated user's own Spotify developer credentials.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $clientId = trim((string) $request->input('spotify_client_id'));
        $reusingSecret = $user->hasCustomSpotifyApp()
            && $clientId !== ''
            && $clientId === $user->spotify_client_id;

        $validated = $request->validate([
            'spotify_client_id' => ['required', 'string', 'max:255'],
            'spotify_client_secret' => [
                $reusingSecret ? 'nullable' : 'required',
                'string',
                'max:255',
            ],
        ]);

        $clientSecret = filled($validated['spotify_client_secret'] ?? null)
            ? trim($validated['spotify_client_secret'])
            : (string) $user->spotify_client_secret;

        $changed = $user->spotify_client_id !== $clientId
            || $user->spotify_client_secret !== $clientSecret;

        $user->forceFill([
            'spotify_client_id' => $clientId,
            'spotify_client_secret' => $clientSecret,
        ]);

        if ($changed) {
            $this->clearSpotifyTokens($user);
        }

        $user->save();

        return back()->with(
            'success',
            'Your Spotify app credentials were saved. Connect Spotify to use them.',
        );
    }

    /**
     * Forget the user's app credentials and fall back to the app defaults.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasCustomSpotifyApp()) {
            $this->clearSpotifyTokens($user);
        }

        $user->forceFill([
            'spotify_client_id' => null,
            'spotify_client_secret' => null,
        ])->save();

        return back()->with('success', 'Your Spotify app credentials were removed.');
    }

    /**
     * Tokens issued by one Spotify app cannot be refreshed by another.
     */
    private function clearSpotifyTokens(User $user): void
    {
        $user->forceFill([
            'spotify_access_token' => null,
            'spotify_refresh_token' => null,
            'spotify_token_expires_at' => null,
            'spotify_scopes' => null,
        ]);
    }
}
