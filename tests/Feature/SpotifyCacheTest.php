<?php

use App\Models\User;
use App\Services\SpotifyCacheService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->connectedToSpotify()->create();
});

it('caches top tracks per user and time range', function () {
    Http::fake([
        'api.spotify.com/v1/me/top/tracks*' => Http::response([
            'items' => [['id' => 'track-a']],
        ]),
    ]);

    $cache = app(SpotifyCacheService::class);

    $first = $cache->topTracks($this->user, 'short_term');
    $second = $cache->topTracks($this->user, 'short_term');
    $otherRange = $cache->topTracks($this->user, 'long_term');

    Http::assertSentCount(2);

    expect($first['items'])->toBe([['id' => 'track-a']])
        ->and($first['stale'])->toBeFalse()
        ->and($second['items'])->toBe($first['items'])
        ->and($otherRange['items'])->toBe([['id' => 'track-a']]);
});

it('serves stale data when Spotify fails', function () {
    $fail = false;

    Http::fake([
        'api.spotify.com/v1/me/top/artists*' => function () use (&$fail) {
            return $fail
                ? Http::response([], 500)
                : Http::response(['items' => [['id' => 'artist-a']]]);
        },
    ]);

    $cache = app(SpotifyCacheService::class);

    $fresh = $cache->topArtists($this->user, 'short_term');

    $this->travel(16)->minutes();

    $fail = true;

    $stale = $cache->topArtists($this->user, 'short_term');

    expect($fresh['stale'])->toBeFalse()
        ->and($stale['stale'])->toBeTrue()
        ->and($stale['items'])->toBe($fresh['items'])
        ->and($stale['cached_at'])->toBe($fresh['cached_at']);
});

it('throws when Spotify fails and nothing is cached', function () {
    Http::fake([
        '*' => Http::response([], 500),
    ]);

    expect(fn () => app(SpotifyCacheService::class)->topTracks($this->user, 'short_term'))
        ->toThrow(RequestException::class);
});

it('derives ranked top albums from the cached top tracks', function () {
    Http::fake([
        'api.spotify.com/v1/me/top/tracks*' => Http::response(['items' => [
            [
                'id' => 'track-a',
                'name' => 'Track A',
                'album' => [
                    'id' => 'album-a',
                    'name' => 'Album A',
                    'artists' => [['name' => 'Artist A']],
                    'images' => [['url' => 'https://images.example/album-a.jpg']],
                    'external_urls' => ['spotify' => 'https://open.spotify.com/album/album-a'],
                ],
            ],
            [
                'id' => 'track-b',
                'name' => 'Track B',
                'album' => [
                    'id' => 'album-a',
                    'name' => 'Album A',
                    'artists' => [['name' => 'Artist A']],
                    'images' => [['url' => 'https://images.example/album-a.jpg']],
                    'external_urls' => ['spotify' => 'https://open.spotify.com/album/album-a'],
                ],
            ],
            [
                'id' => 'track-c',
                'name' => 'Track C',
                'album' => [
                    'id' => 'album-b',
                    'name' => 'Album B',
                    'artists' => [['name' => 'Artist B']],
                    'images' => [['url' => 'https://images.example/album-b.jpg']],
                    'external_urls' => ['spotify' => 'https://open.spotify.com/album/album-b'],
                ],
            ],
        ]]),
        'api.spotify.com/v1/me/top/artists*' => Http::response(['items' => []]),
        'api.spotify.com/v1/me/player/recently-played*' => Http::response(['items' => []]),
        'api.spotify.com/v1/me/player/currently-playing*' => Http::response([], 204),
    ]);

    $this->actingAs($this->user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('topAlbums', 2)
            ->where('topAlbums.0.id', 'album-a')
            ->where('topAlbums.0.name', 'Album A')
            ->where('topAlbums.0.track_count', 2)
            ->where('topAlbums.0.url', 'https://open.spotify.com/album/album-a')
            ->where('topAlbums.1.id', 'album-b'));
});

it('serves the dashboard with stale data when Spotify is unavailable', function () {
    $fail = false;

    $respond = function (array $payload, int $status = 200) use (&$fail) {
        return function () use (&$fail, $payload, $status) {
            return $fail ? Http::response([], 500) : Http::response($payload, $status);
        };
    };

    Http::fake([
        'api.spotify.com/v1/me/top/tracks*' => $respond(['items' => [['id' => 'track-a']]]),
        'api.spotify.com/v1/me/top/artists*' => $respond(['items' => [['id' => 'artist-a']]]),
        'api.spotify.com/v1/me/player/recently-played*' => $respond(['items' => []]),
        'api.spotify.com/v1/me/player/currently-playing*' => $respond([], 204),
    ]);

    $cache = app(SpotifyCacheService::class);

    $cache->topTracks($this->user, 'short_term');
    $cache->topArtists($this->user, 'short_term');
    $cache->recentlyPlayed($this->user);

    $this->travel(16)->minutes();

    $fail = true;

    $this->actingAs($this->user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('spotifyStale', true)
            ->where('spotifyError', null)
            ->has('topTracks', 1)
            ->has('topArtists', 1));
});
