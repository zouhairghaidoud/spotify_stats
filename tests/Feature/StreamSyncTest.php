<?php

use App\Models\Artist;
use App\Models\Stream;
use App\Models\User;
use App\Services\StreamSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<int, array<string, mixed>>  $items
 */
function fakeRecentlyPlayed(array $items): void
{
    Http::fake([
        'api.spotify.com/v1/me/player/recently-played*' => Http::response([
            'items' => $items,
        ]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function playedTrack(string $id, string $playedAt, ?string $context = 'playlist'): array
{
    return [
        'track' => ['id' => $id, 'name' => 'Track '.$id],
        'played_at' => $playedAt,
        'context' => $context === null ? null : ['type' => $context],
    ];
}

it('stores recently played tracks for a user', function () {
    $user = User::factory()->connectedToSpotify()->create();

    fakeRecentlyPlayed([
        playedTrack('track-a', '2026-11-08T10:00:00.000Z'),
        playedTrack('track-b', '2026-11-08T10:05:00.000Z', 'album'),
    ]);

    $stored = app(StreamSyncService::class)->sync($user);

    expect($stored)->toBe(2)
        ->and(Stream::query()->whereBelongsTo($user)->count())->toBe(2)
        ->and(Stream::query()->where('spotify_track_id', 'track-b')->value('context'))->toBe('album');
});

it('does not duplicate plays when syncing again', function () {
    $user = User::factory()->connectedToSpotify()->create();

    fakeRecentlyPlayed([
        playedTrack('track-a', '2026-11-08T10:00:00.000Z'),
        playedTrack('track-b', '2026-11-08T10:05:00.000Z'),
    ]);

    $streams = app(StreamSyncService::class);

    expect($streams->sync($user))->toBe(2)
        ->and($streams->sync($user))->toBe(0)
        ->and(Stream::query()->whereBelongsTo($user)->count())->toBe(2);
});

it('ignores plays without a track id or played_at timestamp', function () {
    $user = User::factory()->connectedToSpotify()->create();

    fakeRecentlyPlayed([
        playedTrack('track-a', '2026-11-08T10:00:00.000Z'),
        ['track' => ['id' => null], 'played_at' => '2026-11-08T10:01:00.000Z'],
        ['track' => ['id' => 'track-c']],
    ]);

    expect(app(StreamSyncService::class)->sync($user))->toBe(1);
});

it('backfills duration and artist for plays stored before analytics columns existed', function () {
    $user = User::factory()->connectedToSpotify()->create();

    DB::table('streams')->insert([
        'user_id' => $user->id,
        'spotify_track_id' => 'track-a',
        'played_at' => '2026-11-08 10:00:00.000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake([
        'api.spotify.com/v1/me/player/recently-played*' => Http::response([
            'items' => [[
                'track' => [
                    'id' => 'track-a',
                    'duration_ms' => 198000,
                    'artists' => [['id' => 'artist-a', 'name' => 'Artist A']],
                ],
                'played_at' => '2026-11-08T10:00:00.000Z',
                'context' => null,
            ]],
        ]),
        'api.spotify.com/v1/me/top/artists*' => Http::response(['items' => []]),
    ]);

    app(StreamSyncService::class)->sync($user);

    $stream = Stream::query()->whereBelongsTo($user)->sole();

    expect($stream->duration_ms)->toBe(198000)
        ->and($stream->artist_id)->toBe('artist-a');
});

it('keeps stored genres when spotify returns an empty genre list', function () {
    $user = User::factory()->connectedToSpotify()->create();

    Artist::query()->create([
        'spotify_artist_id' => 'artist-a',
        'name' => 'Artist A',
        'genres' => ['hip hop'],
    ]);

    Http::fake([
        'api.spotify.com/v1/me/player/recently-played*' => Http::response(['items' => []]),
        'api.spotify.com/v1/me/top/artists*' => Http::response([
            'items' => [['id' => 'artist-a', 'name' => 'Artist A', 'genres' => []]],
        ]),
    ]);

    app(StreamSyncService::class)->sync($user);

    expect(Artist::query()->where('spotify_artist_id', 'artist-a')->sole()->genres)
        ->toBe(['hip hop']);
});

it('throttles the dashboard fallback sync instead of hitting Spotify on every page load', function () {
    $user = User::factory()->connectedToSpotify()->create();

    Http::fake([
        'api.spotify.com/v1/me/top/tracks*' => Http::response(['items' => []]),
        'api.spotify.com/v1/me/top/artists*' => Http::response(['items' => []]),
        'api.spotify.com/v1/me/player/recently-played*' => Http::response(['items' => []]),
        'api.spotify.com/v1/me/player/currently-playing*' => Http::response([], 204),
    ]);

    $this->actingAs($user)->get('/dashboard')->assertOk();
    $this->actingAs($user)->get('/dashboard')->assertOk();

    $recentlyPlayedCalls = Http::recorded(
        fn ($request): bool => str_contains($request->url(), 'recently-played'),
    )->count();

    expect($recentlyPlayedCalls)->toBe(1);
});

it('syncs every connected user from the command', function () {
    $connected = User::factory()->connectedToSpotify()->create();
    $disconnected = User::factory()->create();

    fakeRecentlyPlayed([
        playedTrack('track-a', '2026-11-08T10:00:00.000Z'),
    ]);

    $this->artisan('spotify:sync')->assertSuccessful();

    expect(Stream::query()->whereBelongsTo($connected)->count())->toBe(1)
        ->and(Stream::query()->whereBelongsTo($disconnected)->count())->toBe(0);
});
