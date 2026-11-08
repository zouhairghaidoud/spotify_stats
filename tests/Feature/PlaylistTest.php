<?php

use App\Models\Stream;
use App\Models\Track;
use App\Models\User;
use App\Services\PlaylistService;
use App\Services\SpotifyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function playlistTrack(string $id, string $name = 'Track'): Track
{
    return Track::query()->create([
        'spotify_track_id' => $id,
        'name' => $name.' '.$id,
        'artist_name' => 'Artist',
        'album_name' => 'Album',
        'album_image_url' => "https://images.example/{$id}.jpg",
        'duration_ms' => 200000,
    ]);
}

function playlistPlay(User $user, string $trackId, string $playedAt): Stream
{
    return Stream::query()->create([
        'user_id' => $user->id,
        'spotify_track_id' => $trackId,
        'duration_ms' => 200000,
        'artist_id' => 'artist-1',
        'played_at' => $playedAt,
    ]);
}

function fakePlaylistApi(array $topTracks = []): void
{
    Http::fake([
        'api.spotify.com/v1/me/top/tracks*' => Http::response(['items' => $topTracks]),
        'api.spotify.com/v1/me/playlists' => Http::response([
            'id' => 'playlist-1',
            'name' => 'Generated playlist',
            'external_urls' => ['spotify' => 'https://open.spotify.com/playlist/playlist-1'],
        ], 201),
        'api.spotify.com/v1/playlists/*/items' => Http::response(['snapshot_id' => 'snapshot'], 201),
    ]);
}

it('requests the playlist scope during authorization', function () {
    $url = app(SpotifyService::class)->authorizationUrl('state-token');

    expect($url)->toContain('playlist-modify-private');
});

it('stores the granted scopes when connecting spotify', function () {
    Http::fake([
        'accounts.spotify.com/api/token' => Http::response([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
            'scope' => 'user-read-email user-top-read playlist-modify-private',
        ]),
        'api.spotify.com/v1/me' => Http::response([
            'id' => 'spotify-user',
            'email' => 'listener@example.com',
            'display_name' => 'Listener',
        ]),
        'api.spotify.com/v1/me/player/recently-played*' => Http::response(['items' => []]),
        'api.spotify.com/v1/me/top/artists*' => Http::response(['items' => []]),
    ]);

    $this->withSession([
        'spotify_oauth_state' => 'state-token',
        'spotify_oauth_intent' => 'login',
    ])->get('/auth/spotify/callback?state=state-token&code=authorization-code')
        ->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'listener@example.com')->sole();

    expect($user->spotify_scopes)->toContain('playlist-modify-private')
        ->and($user->hasPlaylistScope())->toBeTrue();
});

it('creates a private playlist from top tracks', function () {
    $user = User::factory()->connectedToSpotify()->withPlaylistScope()->create();

    fakePlaylistApi([
        ['id' => 'track-a', 'uri' => 'spotify:track:track-a'],
        ['id' => 'track-b', 'uri' => 'spotify:track:track-b'],
    ]);

    $this->actingAs($user)
        ->post('/playlists/top-tracks', ['range' => 'short_term'])
        ->assertRedirect(route('playlists.index'))
        ->assertSessionHas('success')
        ->assertSessionHas('created_playlist', fn (array $playlist): bool => $playlist['id'] === 'playlist-1'
            && $playlist['track_count'] === 2
            && $playlist['url'] === 'https://open.spotify.com/playlist/playlist-1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.spotify.com/v1/me/playlists'
        && $request['public'] === false
        && $request['name'] === 'My Top Tracks · Last 4 Weeks');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/playlists/playlist-1/items')
        && $request['uris'] === ['spotify:track:track-a', 'spotify:track:track-b']);
});

it('adds top tracks to a playlist in batches of one hundred', function () {
    $user = User::factory()->connectedToSpotify()->withPlaylistScope()->create();

    $items = collect(range(1, 150))
        ->map(fn (int $index): array => [
            'id' => 'track-'.$index,
            'uri' => 'spotify:track:track-'.$index,
        ])
        ->all();

    fakePlaylistApi($items);

    $this->actingAs($user)
        ->post('/playlists/top-tracks', ['range' => 'long_term'])
        ->assertRedirect(route('playlists.index'));

    $batches = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/items'))
        ->map(fn (array $pair): int => count($pair[0]['uris']))
        ->values()
        ->all();

    expect($batches)->toBe([100, 50]);
});

it('lists forgotten favorites that were played enough and not recently', function () {
    $this->travelTo('2026-11-08 12:00:00');

    $user = User::factory()->connectedToSpotify()->withPlaylistScope()->create();

    playlistTrack('track-a', 'Old Favorite');
    playlistTrack('track-b', 'Still Recent');
    playlistTrack('track-c', 'One Play');
    playlistTrack('track-d', 'Another Oldie');

    foreach (range(1, 3) as $index) {
        playlistPlay($user, 'track-a', now()->subDays(20)->addMinutes($index)->toDateTimeString());
    }

    playlistPlay($user, 'track-b', now()->subDays(30)->toDateTimeString());
    playlistPlay($user, 'track-b', now()->subDays(30)->addHour()->toDateTimeString());
    playlistPlay($user, 'track-b', now()->subDays(2)->toDateTimeString());

    playlistPlay($user, 'track-c', now()->subDays(20)->toDateTimeString());

    playlistPlay($user, 'track-d', now()->subDays(30)->toDateTimeString());
    playlistPlay($user, 'track-d', now()->subDays(29)->toDateTimeString());

    $forgotten = app(PlaylistService::class)->forgottenFavorites($user);

    expect(array_column($forgotten, 'spotify_track_id'))->toBe(['track-a', 'track-d'])
        ->and($forgotten[0]['name'])->toBe('Old Favorite track-a')
        ->and($forgotten[0]['plays'])->toBe(3)
        ->and($forgotten[0]['uri'])->toBe('spotify:track:track-a');
});

it('shows the playlist tools page with props', function () {
    $user = User::factory()->connectedToSpotify()->withPlaylistScope()->create();

    $this->travelTo('2026-11-08 12:00:00');

    playlistTrack('track-a');
    playlistPlay($user, 'track-a', now()->subDays(20)->toDateTimeString());
    playlistPlay($user, 'track-a', now()->subDays(21)->toDateTimeString());

    $this->actingAs($user)
        ->get('/playlists')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Playlists')
            ->where('connected', true)
            ->where('canCreate', true)
            ->where('forgottenDays', PlaylistService::FORGOTTEN_DAYS)
            ->has('ranges.short_term')
            ->has('forgotten', 1)
            ->where('forgotten.0.spotify_track_id', 'track-a')
            ->where('createdPlaylist', null));
});

it('creates a playlist from forgotten favorites', function () {
    $this->travelTo('2026-11-08 12:00:00');

    $user = User::factory()->connectedToSpotify()->withPlaylistScope()->create();

    playlistTrack('track-a');
    playlistTrack('track-b');

    playlistPlay($user, 'track-a', now()->subDays(20)->toDateTimeString());
    playlistPlay($user, 'track-a', now()->subDays(21)->toDateTimeString());
    playlistPlay($user, 'track-a', now()->subDays(22)->toDateTimeString());
    playlistPlay($user, 'track-b', now()->subDays(20)->toDateTimeString());
    playlistPlay($user, 'track-b', now()->subDays(25)->toDateTimeString());

    fakePlaylistApi();

    $this->actingAs($user)
        ->post('/playlists/forgotten')
        ->assertRedirect(route('playlists.index'))
        ->assertSessionHas('created_playlist');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.spotify.com/v1/me/playlists'
        && str_starts_with((string) $request['name'], 'Forgotten Favorites ·'));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/playlists/playlist-1/items')
        && $request['uris'] === ['spotify:track:track-a', 'spotify:track:track-b']);
});

it('refuses to create playlists without the playlist scope', function () {
    Http::preventStrayRequests();

    $user = User::factory()->connectedToSpotify()->create();

    $this->actingAs($user)
        ->get('/playlists')
        ->assertInertia(fn (Assert $page) => $page->where('canCreate', false));

    $this->actingAs($user)
        ->post('/playlists/top-tracks', ['range' => 'short_term'])
        ->assertRedirect(route('playlists.index'))
        ->assertSessionHas('error', 'Reconnect Spotify to allow creating playlists.');

    Http::assertNothingSent();
});

it('reports a spotify permission failure', function () {
    $user = User::factory()->connectedToSpotify()->withPlaylistScope()->create();

    Http::fake([
        'api.spotify.com/v1/me/top/tracks*' => Http::response(['items' => [
            ['id' => 'track-a', 'uri' => 'spotify:track:track-a'],
        ]]),
        'api.spotify.com/v1/me/playlists' => Http::response(['error' => ['status' => 403]], 403),
    ]);

    $this->actingAs($user)
        ->post('/playlists/top-tracks', ['range' => 'short_term'])
        ->assertRedirect(route('playlists.index'))
        ->assertSessionHas('error', 'Spotify refused the playlist request. Reconnect your account to grant playlist access.');
});
