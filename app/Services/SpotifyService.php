<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SpotifyService
{
    private const ACCOUNTS_URL = 'https://accounts.spotify.com';

    private const API_URL = 'https://api.spotify.com/v1';

    /**
     * Scope required to create private playlists.
     */
    public const PLAYLIST_SCOPE = 'playlist-modify-private';

    /**
     * The scopes requested during authorization.
     *
     * @var list<string>
     */
    private const SCOPES = [
        'user-read-email',
        'user-top-read',
        'user-read-recently-played',
        'user-read-currently-playing',
        self::PLAYLIST_SCOPE,
    ];

    /**
     * Build the Spotify authorization URL the user should be redirected to.
     *
     * A signed-in user with their own developer app uses those credentials;
     * guests always use the application default so `/login` keeps working.
     */
    public function authorizationUrl(string $state, ?User $user = null): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId($user),
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
    public function exchangeCode(string $code, ?User $user = null): array
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ], $user);
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
        ], $user);

        $attributes = [
            'spotify_access_token' => $tokens['access_token'],
            'spotify_refresh_token' => $tokens['refresh_token'] ?? $refreshToken,
            'spotify_token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ];

        if (isset($tokens['scope'])) {
            $attributes['spotify_scopes'] = (string) $tokens['scope'];
        }

        $user->forceFill($attributes)->save();
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
     * Create an empty private playlist for the current Spotify user.
     *
     * The old `POST /users/{user_id}/playlists` endpoint was removed in
     * Spotify's February 2026 Web API changes; `POST /me/playlists` replaces it.
     *
     * @return array<string, mixed>
     */
    public function createPlaylist(User $user, string $name, string $description): array
    {
        return $this->postJson($user, '/me/playlists', [
            'name' => $name,
            'description' => $description,
            'public' => false,
        ])->throw()->json();
    }

    /**
     * Append tracks to a playlist, sending Spotify's maximum of 100 URIs per
     * request. `POST /playlists/{id}/tracks` was removed in February 2026.
     *
     * @param  list<string>  $uris
     */
    public function addTracksToPlaylist(User $user, string $playlistId, array $uris): void
    {
        $uris = array_values(array_unique(array_filter($uris)));

        foreach (array_chunk($uris, 100) as $chunk) {
            $this->postJson($user, '/playlists/'.rawurlencode($playlistId).'/items', [
                'uris' => $chunk,
            ])->throw();
        }
    }

    /**
     * Remove a playlist from the user's library (unfollow it).
     *
     * Used to clean up an empty playlist when adding its tracks failed.
     */
    public function deletePlaylist(User $user, string $playlistId): void
    {
        $url = self::API_URL.'/playlists/'.rawurlencode($playlistId).'/followers';

        $response = Http::withToken($this->accessToken($user))
            ->acceptJson()
            ->delete($url);

        if ($response->status() === 401) {
            $this->refreshAccessToken($user);

            $response = Http::withToken($this->accessToken($user))
                ->acceptJson()
                ->delete($url);
        }

        $response->throw();
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
     * Send a JSON POST, retrying once after a token refresh on 401.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postJson(User $user, string $path, array $payload): Response
    {
        $response = Http::withToken($this->accessToken($user))
            ->acceptJson()
            ->post(self::API_URL.$path, $payload);

        if ($response->status() === 401) {
            $this->refreshAccessToken($user);

            $response = Http::withToken($this->accessToken($user))
                ->acceptJson()
                ->post(self::API_URL.$path, $payload);
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function requestToken(array $payload, ?User $user = null): array
    {
        return Http::asForm()
            ->withBasicAuth($this->clientId($user), $this->clientSecret($user))
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

    /**
     * Get an app-level token with the Client Credentials flow.
     *
     * This flow is not limited by the five-user Development Mode allowlist, so
     * imported history can be enriched without every user connecting OAuth.
     */
    public function clientCredentialsToken(): string
    {
        $clientId = $this->clientId();
        $clientSecret = $this->clientSecret();

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('No Spotify app credentials are configured.');
        }

        $key = 'spotify:client-credentials:'.sha1($clientId);

        return Cache::remember($key, now()->addMinutes(55), function () use ($clientId, $clientSecret): string {
            $tokens = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->acceptJson()
                ->post(self::ACCOUNTS_URL.'/api/token', ['grant_type' => 'client_credentials'])
                ->throw()
                ->json();

            return (string) ($tokens['access_token'] ?? '');
        });
    }

    /**
     * Look up full track metadata by id, 50 ids per request.
     *
     * Failed chunks are skipped so a partial enrichment still succeeds.
     *
     * @param  list<string>  $trackIds
     * @return array<string, array<string, mixed>>
     */
    public function lookupTracks(array $trackIds): array
    {
        $trackIds = array_values(array_unique(array_filter($trackIds)));
        $tracks = [];

        if ($trackIds === []) {
            return $tracks;
        }

        $token = $this->clientCredentialsToken();

        foreach (array_chunk($trackIds, 50) as $chunk) {
            try {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->get(self::API_URL.'/tracks', ['ids' => implode(',', $chunk)]);

                if ($response->status() === 401) {
                    Cache::forget('spotify:client-credentials:'.sha1($this->clientId()));
                    $token = $this->clientCredentialsToken();

                    $response = Http::withToken($token)
                        ->acceptJson()
                        ->get(self::API_URL.'/tracks', ['ids' => implode(',', $chunk)]);
                }

                if (! $response->successful()) {
                    continue;
                }

                foreach ((array) ($response->json('tracks') ?? []) as $track) {
                    if (is_array($track) && filled($track['id'] ?? null)) {
                        $tracks[(string) $track['id']] = $track;
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $tracks;
    }

    /**
     * Fetch public track metadata from Spotify's embed page.
     *
     * The Web API `/v1/tracks` endpoint returns nothing for apps created after
     * November 2024, and the oEmbed response omits the artist name. The public
     * embed page embeds a JSON payload that includes the track title, artists,
     * cover art, and duration, so we read that first and fall back to oEmbed.
     *
     * @return array<string, mixed>|null
     */
    public function lookupTrackViaOembed(string $trackId): ?array
    {
        $embed = $this->lookupTrackEmbed($trackId);

        if ($embed !== null) {
            return $embed;
        }

        try {
            $response = Http::acceptJson()->get('https://open.spotify.com/oembed', [
                'url' => 'spotify:track:'.$trackId,
            ]);

            return $response->successful() ? $response->json() : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupTrackEmbed(string $trackId): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; SpotifyStats/1.0)',
            ])->get('https://open.spotify.com/embed/track/'.$trackId);

            if (! $response->successful()) {
                return null;
            }

            if (preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $response->body(), $matches) !== 1) {
                return null;
            }

            $entity = data_get(json_decode($matches[1], true), 'props.pageProps.state.data.entity');

            if (! is_array($entity)) {
                return null;
            }

            $firstArtist = data_get($entity, 'artists.0');
            $firstArtist = is_array($firstArtist) ? $firstArtist : [];

            return [
                'title' => $entity['name'] ?? null,
                'author_name' => $firstArtist['name'] ?? null,
                'artist_id' => $this->artistIdFromUri($firstArtist['uri'] ?? null),
                'thumbnail_url' => data_get($entity, 'visualIdentity.image.0.url'),
                'duration_ms' => is_numeric($entity['duration'] ?? null) ? (int) $entity['duration'] : null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function artistIdFromUri(mixed $uri): ?string
    {
        if (! is_string($uri) || ! str_starts_with($uri, 'spotify:artist:')) {
            return null;
        }

        $id = trim(substr($uri, strlen('spotify:artist:')));

        return $id !== '' ? $id : null;
    }

    private function clientId(?User $user = null): string
    {
        if ($user !== null && $user->hasCustomSpotifyApp()) {
            return (string) $user->spotify_client_id;
        }

        return (string) config('services.spotify.client_id');
    }

    private function clientSecret(?User $user = null): string
    {
        if ($user !== null && $user->hasCustomSpotifyApp()) {
            return (string) $user->spotify_client_secret;
        }

        return (string) config('services.spotify.client_secret');
    }

    private function redirectUri(): string
    {
        return (string) config('services.spotify.redirect');
    }
}
