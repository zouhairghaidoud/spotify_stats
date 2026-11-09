<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;

trait ProvidesSpotifyApp
{
    /**
     * Shape the BYOK settings the Vue forms need.
     *
     * @return array{configured: bool, client_id: string|null, redirect_uri: string, env_configured: bool}
     */
    protected function spotifyAppProps(User $user): array
    {
        return [
            'configured' => $user->hasCustomSpotifyApp(),
            'client_id' => $user->spotify_client_id,
            'redirect_uri' => (string) config('services.spotify.redirect'),
            'env_configured' => filled(config('services.spotify.client_id'))
                && filled(config('services.spotify.client_secret')),
        ];
    }
}
