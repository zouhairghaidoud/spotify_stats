<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SpotifyService
{
    private const ACCOUNTS_URL = 'https://accounts.spotify.com';

    private const API_URL = 'https://api.spotify.com/v1';

    /**
     * The scopes required by the dashboard.
     *
     * @var list<string>
     */
    private const SCOPES = [
        'user-read-email',
        'user-top-read',
        'user-read-recently-played',
        'user-read-currently-playing',
    ];

    /**
     * Build the Spotify authorization URL the user should be redirected to.
     */
    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
        ]);

        return self::ACCOUNTS_URL.'/authorize?'.$query;
    }

    /**
     * Exchange the authorization code returned by Spotify for an access token.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code): array
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * Refresh the user's access token and persist the new credentials.
     */
    public function refreshAccessToken(User $user): void
    {
        $refreshToken = $user->spotify_refresh_token;

        if (blank($refreshToken)) {
            throw new RuntimeException('The user has no Spotify refresh token.');
        }

        $tokens = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $user->forceFill([
            'spotify_access_token' => $tokens['access_token'],
            'spotify_refresh_token' => $tokens['refresh_token'] ?? $refreshToken,
            'spotify_token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ])->save();
    }

    /**
     * Get a valid access token, refreshing it when it is missing or about to expire.
     */
    public function accessToken(User $user): string
    {
        $expiresAt = $user->spotify_token_expires_at;

        if (blank($user->spotify_access_token) || $expiresAt === null || $expiresAt->lessThanOrEqualTo(now()->addMinutes(5))) {
            $this->refreshAccessToken($user);
        }

        return (string) $user->spotify_access_token;
    }

    /**
     * Get the authenticated Spotify profile for the given access token.
     *
     * @return array<string, mixed>
     */
    public function getProfile(string $accessToken): array
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->get(self::API_URL.'/me')
            ->throw()
            ->json();
    }

    /**
     * Get the user's most played tracks.
     *
     * @return array<string, mixed>
     */
    public function getTopTracks(User $user, string $timeRange = 'short_term', int $limit = 50): array
    {
        return $this->apiGet($user, '/me/top/tracks', [
            'time_range' => $this->timeRange($timeRange),
            'limit' => $this->limit($limit),
        ]) ?? ['items' => []];
    }

    /**
     * Get the user's most played artists.
     *
     * @return array<string, mixed>
     */
    public function getTopArtists(User $user, string $timeRange = 'short_term', int $limit = 50): array
    {
        return $this->apiGet($user, '/me/top/artists', [
            'time_range' => $this->timeRange($timeRange),
            'limit' => $this->limit($limit),
        ]) ?? ['items' => []];
    }

    /**
     * Get the user's recently played tracks.
     *
     * @return array<string, mixed>
     */
    public function getRecentlyPlayed(User $user, int $limit = 50): array
    {
        return $this->apiGet($user, '/me/player/recently-played', [
            'limit' => $this->limit($limit),
        ]) ?? ['items' => []];
    }

    /**
     * Get the track currently playing, or null when nothing is playing.
     *
     * @return array<string, mixed>|null
     */
    public function getCurrentlyPlaying(User $user): ?array
    {
        return $this->apiGet($user, '/me/player/currently-playing');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function apiGet(User $user, string $path, array $query = []): ?array
    {
        $response = $this->send($user, $path, $query);

        if ($response->status() === 401) {
            $this->refreshAccessToken($user);
            $response = $this->send($user, $path, $query);
        }

        if ($response->noContent()) {
            return null;
        }

        return $response->throw()->json();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function send(User $user, string $path, array $query): Response
    {
        return Http::withToken($this->accessToken($user))
            ->acceptJson()
            ->get(self::API_URL.$path, $query);
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function requestToken(array $payload): array
    {
        return Http::asForm()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->acceptJson()
            ->post(self::ACCOUNTS_URL.'/api/token', $payload)
            ->throw()
            ->json();
    }

    private function timeRange(string $timeRange): string
    {
        return in_array($timeRange, ['short_term', 'medium_term', 'long_term'], true)
            ? $timeRange
            : 'short_term';
    }

    private function limit(int $limit): int
    {
        return max(1, min($limit, 50));
    }

    private function clientId(): string
    {
        return (string) config('services.spotify.client_id');
    }

    private function clientSecret(): string
    {
        return (string) config('services.spotify.client_secret');
    }

    private function redirectUri(): string
    {
        return (string) config('services.spotify.redirect');
    }
}
