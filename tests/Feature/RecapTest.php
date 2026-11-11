<?php

use App\Models\Artist;
use App\Models\Stream;
use App\Models\Track;
use App\Models\User;
use App\Services\RecapService;
use App\Services\StreamSyncService;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<int, string>  $genres
 */
function recapArtist(string $id, string $name, array $genres): Artist
{
    return Artist::query()->create([
        'spotify_artist_id' => $id,
        'name' => $name,
        'genres' => $genres,
    ]);
}

function recapPlay(
    User $user,
    string $trackId,
    string $playedAt,
    int $durationMs,
    string $artistId,
): Stream {
    return Stream::query()->create([
        'user_id' => $user->id,
        'spotify_track_id' => $trackId,
        'duration_ms' => $durationMs,
        'artist_id' => $artistId,
        'played_at' => $playedAt,
    ]);
}

function recapTrack(string $id, string $name, string $artistId, string $artistName): Track
{
    return Track::query()->create([
        'spotify_track_id' => $id,
        'name' => $name,
        'artist_id' => $artistId,
        'artist_name' => $artistName,
        'album_name' => 'Album',
        'album_image_url' => "https://images.example/{$id}.jpg",
        'duration_ms' => 210000,
    ]);
}

it('builds the recap from stored plays', function () {
    $this->travelTo('2026-11-08 12:00:00');

    $user = User::factory()->create();

    recapArtist('artist-1', 'Artist One', ['indie pop', 'synthpop']);
    recapArtist('artist-2', 'Artist Two', ['indie pop']);

    recapTrack('track-a', 'Track A', 'artist-1', 'Artist One');
    recapTrack('track-b', 'Track B', 'artist-2', 'Artist Two');

    recapPlay($user, 'track-a', '2026-11-07 14:00:00', 210000, 'artist-1');
    recapPlay($user, 'track-a', '2026-11-07 14:10:00', 210000, 'artist-1');
    recapPlay($user, 'track-b', '2026-11-07 15:00:00', 180000, 'artist-2');
    recapPlay($user, 'track-b', '2026-11-06 15:00:00', 180000, 'artist-2');
    recapPlay($user, 'track-a', '2026-11-05 09:00:00', 210000, 'artist-1');

    $recap = app(RecapService::class)->forUser($user, 'UTC');

    expect($recap['overview']['plays'])->toBe(5)
        ->and($recap['overview']['minutes'])->toBe(16)
        ->and($recap['top_tracks'])->toHaveCount(2)
        ->and($recap['top_tracks'][0]['name'])->toBe('Track A')
        ->and($recap['top_tracks'][0]['plays'])->toBe(3)
        ->and($recap['top_tracks'][0]['cover_url'])->toBe('https://images.example/track-a.jpg')
        ->and($recap['top_artist']['name'])->toBe('Artist One')
        ->and($recap['top_artist']['plays'])->toBe(3)
        ->and($recap['top_artist']['share'])->toBe(0.6)
        ->and($recap['top_genres'][0])->toBe(['genre' => 'indie pop', 'plays' => 5])
        ->and($recap['peak_hour'])->toBe(['hour' => 14, 'plays' => 2]);
});

it('scopes the recap to a requested year', function () {
    $user = User::factory()->create();

    recapTrack('track-a', 'Track A', 'artist-1', 'Artist One');
    recapTrack('track-b', 'Track B', 'artist-1', 'Artist One');

    recapPlay($user, 'track-a', '2025-06-01 10:00:00', 210000, 'artist-1');
    recapPlay($user, 'track-a', '2025-06-02 10:00:00', 210000, 'artist-1');
    recapPlay($user, 'track-b', '2026-06-01 10:00:00', 210000, 'artist-1');

    $recap = app(RecapService::class)->forUser($user, 'UTC', 2025);

    expect($recap['year'])->toBe(2025)
        ->and($recap['overview']['plays'])->toBe(2)
        ->and($recap['top_tracks'])->toHaveCount(1)
        ->and($recap['top_tracks'][0]['name'])->toBe('Track A')
        ->and($recap['top_artist']['plays'])->toBe(2)
        ->and($recap['top_album']['plays'])->toBe(2)
        ->and($recap['period']['from'])->toStartWith('2025-06-01');
});

it('lists the years available in the recap', function () {
    $user = User::factory()->create();

    recapPlay($user, 'track-a', '2024-03-01 10:00:00', 210000, 'artist-1');
    recapPlay($user, 'track-a', '2026-03-01 10:00:00', 210000, 'artist-1');

    expect(app(RecapService::class)->availableYears($user))->toBe([2026, 2025, 2024]);
});

it('includes the most played album in the recap', function () {
    $user = User::factory()->create();

    recapTrack('track-a', 'Track A', 'artist-1', 'Artist One');
    recapTrack('track-b', 'Track B', 'artist-1', 'Artist One');

    recapPlay($user, 'track-a', '2026-11-07 14:00:00', 210000, 'artist-1');
    recapPlay($user, 'track-a', '2026-11-07 14:10:00', 210000, 'artist-1');
    recapPlay($user, 'track-b', '2026-11-07 15:00:00', 210000, 'artist-1');

    $album = app(RecapService::class)->forUser($user, 'UTC')['top_album'];

    expect($album)->not->toBeNull()
        ->and($album['album_name'])->toBe('Album')
        ->and($album['artist_name'])->toBe('Artist One')
        ->and($album['plays'])->toBe(3)
        ->and($album['minutes'])->toBe(11)
        ->and($album['unique_tracks_played'])->toBe(2)
        ->and($album['cover_url'])->not->toBeNull();
});

it('returns an empty recap for a user without plays', function () {
    $recap = app(RecapService::class)->forUser(User::factory()->create(), 'UTC');

    expect($recap['overview']['plays'])->toBe(0)
        ->and($recap['top_tracks'])->toBe([])
        ->and($recap['top_artist'])->toBeNull()
        ->and($recap['top_album'])->toBeNull()
        ->and($recap['top_genres'])->toBe([])
        ->and($recap['peak_hour'])->toBeNull()
        ->and($recap['period']['from'])->toBeNull();
});

it('renders the owner recap page', function () {
    $user = User::factory()->create();

    recapArtist('artist-1', 'Artist One', ['indie pop']);
    recapTrack('track-a', 'Track A', 'artist-1', 'Artist One');
    recapPlay($user, 'track-a', '2026-11-07 14:00:00', 210000, 'artist-1');

    $this->actingAs($user)
        ->get('/recap')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recap')
            ->where('shared', false)
            ->where('recap.overview.plays', 1)
            ->where('recap.top_artist.name', 'Artist One')
            ->where('recap.top_tracks.0.name', 'Track A')
            ->where('publicUrl', null));
});

it('renders the recap for a selected year', function () {
    $user = User::factory()->create();

    recapTrack('track-a', 'Track A', 'artist-1', 'Artist One');
    recapPlay($user, 'track-a', '2025-06-01 10:00:00', 210000, 'artist-1');
    recapPlay($user, 'track-a', '2026-06-01 10:00:00', 210000, 'artist-1');

    $this->actingAs($user)
        ->get('/recap?year=2025')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recap')
            ->where('year', 2025)
            ->where('years', [2026, 2025])
            ->where('recap.year', 2025)
            ->where('recap.overview.plays', 1)
            ->where('recap.period.from', fn ($value) => str_starts_with((string) $value, '2025-06-01')));
});

it('compares a recap year with another year', function () {
    $user = User::factory()->create();

    recapTrack('track-a', 'Track A', 'artist-1', 'Artist One');
    recapPlay($user, 'track-a', '2025-06-01 10:00:00', 210000, 'artist-1');
    recapPlay($user, 'track-a', '2025-06-02 10:00:00', 210000, 'artist-1');
    recapPlay($user, 'track-a', '2026-06-01 10:00:00', 210000, 'artist-1');

    $this->actingAs($user)
        ->get('/recap?year=2026&compare=2025')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recap')
            ->where('year', 2026)
            ->where('compareYear', 2025)
            ->where('recap.overview.plays', 1)
            ->where('comparison.year', 2025)
            ->where('comparison.overview.plays', 2));
});

it('ignores a comparison year when no year is selected', function () {
    $user = User::factory()->create();

    recapPlay($user, 'track-a', '2025-06-01 10:00:00', 210000, 'artist-1');

    $this->actingAs($user)
        ->get('/recap?compare=2025')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recap')
            ->where('year', null)
            ->where('compareYear', null)
            ->where('comparison', null));
});

it('ignores a comparison year that matches the selected year', function () {
    $user = User::factory()->create();

    recapPlay($user, 'track-a', '2025-06-01 10:00:00', 210000, 'artist-1');

    $this->actingAs($user)
        ->get('/recap?year=2025&compare=2025')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recap')
            ->where('year', 2025)
            ->where('compareYear', null)
            ->where('comparison', null));
});

it('ignores recap years that are not in the history', function () {
    $user = User::factory()->create();

    recapPlay($user, 'track-a', '2026-06-01 10:00:00', 210000, 'artist-1');

    $this->actingAs($user)
        ->get('/recap?year=1999')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recap')
            ->where('year', null)
            ->where('recap.year', null)
            ->where('recap.overview.plays', 1));
});

it('creates and revokes a public recap link', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withUnencryptedCookie('timezone', 'Europe/Berlin')
        ->post('/recap/share')
        ->assertRedirect();

    $user->refresh();

    expect($user->recap_token)->toBeString()
        ->and(strlen((string) $user->recap_token))->toBe(48)
        ->and($user->recap_timezone)->toBe('Europe/Berlin');

    $token = $user->recap_token;

    $this->actingAs($user)
        ->get('/recap')
        ->assertInertia(fn (Assert $page) => $page
            ->where('publicUrl', route('recap.public', $token)));

    $this->actingAs($user)->delete('/recap/share')->assertRedirect();

    $user->refresh();

    expect($user->recap_token)->toBeNull()
        ->and($user->recap_timezone)->toBeNull();
});

it('serves the public recap for a valid token', function () {
    $user = User::factory()->create();

    recapArtist('artist-1', 'Artist One', ['indie pop']);
    recapTrack('track-a', 'Track A', 'artist-1', 'Artist One');
    recapPlay($user, 'track-a', '2026-11-07 14:00:00', 210000, 'artist-1');

    $user->forceFill([
        'recap_token' => 'share-token',
        'recap_timezone' => 'Europe/Berlin',
    ])->save();

    $this->get('/u/share-token')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recap')
            ->where('shared', true)
            ->where('recap.timezone', 'Europe/Berlin')
            ->where('recap.top_artist.name', 'Artist One')
            ->where('og.image', route('recap.image', 'share-token')));

    $this->get('/u/not-a-token')->assertNotFound();
});

it('renders open graph meta tags in the public page html', function () {
    $user = User::factory()->create();
    $user->forceFill(['recap_token' => 'share-token'])->save();

    $this->get('/u/share-token')
        ->assertOk()
        ->assertSee('property="og:title"', false)
        ->assertSee('name="twitter:card"', false)
        ->assertSee(route('recap.image', 'share-token'), false);
});

it('renders the open graph image as a png', function () {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('The GD extension is not available.');
    }

    $user = User::factory()->create();

    recapArtist('artist-1', 'Artist One', []);
    recapTrack('track-a', 'Track A', 'artist-1', 'Artist One');
    recapPlay($user, 'track-a', '2026-11-07 14:00:00', 210000, 'artist-1');

    $user->forceFill(['recap_token' => 'share-token'])->save();

    $response = $this->get('/u/share-token/og.png');

    $response->assertOk()->assertHeader('Content-Type', 'image/png');

    expect(substr((string) $response->getContent(), 0, 8))
        ->toBe("\x89PNG\r\n\x1a\n");
});

it('stores track metadata while syncing', function () {
    $user = User::factory()->connectedToSpotify()->create();

    Http::fake([
        'api.spotify.com/v1/me/player/recently-played*' => Http::response([
            'items' => [[
                'track' => [
                    'id' => 'track-a',
                    'name' => 'Track A',
                    'duration_ms' => 205000,
                    'artists' => [['id' => 'artist-1', 'name' => 'Artist One']],
                    'album' => [
                        'name' => 'Album One',
                        'images' => [['url' => 'https://images.example/cover.jpg']],
                    ],
                ],
                'played_at' => '2026-11-08T10:00:00.000Z',
                'context' => null,
            ]],
        ]),
        'api.spotify.com/v1/me/top/artists*' => Http::response(['items' => []]),
    ]);

    app(StreamSyncService::class)->sync($user);

    $track = Track::query()->where('spotify_track_id', 'track-a')->sole();

    expect($track->name)->toBe('Track A')
        ->and($track->artist_name)->toBe('Artist One')
        ->and($track->album_name)->toBe('Album One')
        ->and($track->album_image_url)->toBe('https://images.example/cover.jpg')
        ->and($track->duration_ms)->toBe(205000);
});
