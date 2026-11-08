<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

function nowPlayingPayload(): array
{
    return [
        'progress_ms' => 61000,
        'is_playing' => true,
        'item' => [
            'id' => 'track-a',
            'name' => 'Midnight City',
            'type' => 'track',
            'uri' => 'spotify:track:track-a',
            'duration_ms' => 244000,
            'external_urls' => ['spotify' => 'https://open.spotify.com/track/track-a'],
            'album' => [
                'name' => 'Hurry Up, We Are Dreaming',
                'images' => [
                    ['url' => 'https://images.example/large.jpg', 'width' => 640, 'height' => 640],
                    ['url' => 'https://images.example/small.jpg', 'width' => 64, 'height' => 64],
                    ['url' => 'https://images.example/tiny.jpg', 'width' => 32, 'height' => 32],
                ],
            ],
            'artists' => [
                ['name' => 'M83'],
                ['name' => 'Guest'],
            ],
        ],
    ];
}

it('returns the current track for polling', function () {
    $user = User::factory()->connectedToSpotify()->create();

    Http::fake([
        'api.spotify.com/v1/me/player/currently-playing*' => Http::response(nowPlayingPayload()),
    ]);

    $this->actingAs($user)
        ->getJson('/now-playing')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('item.name', 'Midnight City')
        ->assertJsonPath('item.artists.0.name', 'M83')
        ->assertJsonPath('item.album.images.0.url', 'https://images.example/large.jpg')
        ->assertJsonCount(2, 'item.album.images')
        ->assertJsonPath('progress_ms', 61000)
        ->assertJsonPath('is_playing', true)
        ->assertJsonStructure(['item', 'progress_ms', 'is_playing', 'fetched_at']);
});

it('returns an empty payload when nothing is playing', function () {
    $user = User::factory()->connectedToSpotify()->create();

    Http::fake([
        'api.spotify.com/v1/me/player/currently-playing*' => Http::response('', 204),
    ]);

    $this->actingAs($user)
        ->getJson('/now-playing')
        ->assertOk()
        ->assertJsonPath('item', null)
        ->assertJsonPath('progress_ms', 0)
        ->assertJsonPath('is_playing', false);
});

it('returns an empty payload when spotify fails', function () {
    $user = User::factory()->connectedToSpotify()->create();

    Http::fake([
        'api.spotify.com/v1/me/player/currently-playing*' => Http::response(['error' => 'boom'], 500),
    ]);

    $this->actingAs($user)
        ->getJson('/now-playing')
        ->assertOk()
        ->assertJsonPath('item', null)
        ->assertJsonPath('is_playing', false);
});

it('returns an empty payload for users without a connection', function () {
    $user = User::factory()->create();

    Http::preventStrayRequests();

    $this->actingAs($user)
        ->getJson('/now-playing')
        ->assertOk()
        ->assertJsonPath('item', null);

    Http::assertNothingSent();
});

it('requires authentication for now playing', function () {
    $this->getJson('/now-playing')->assertUnauthorized();
});
