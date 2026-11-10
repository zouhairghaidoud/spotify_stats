<?php

use App\Models\Artist;
use App\Models\Stream;
use App\Models\Track;
use App\Models\User;
use App\Services\ListeningAnalyticsService;
use App\Services\StreamSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<int, string>  $genres
 */
function artistWithGenres(string $id, string $name, array $genres): Artist
{
    return Artist::query()->create([
        'spotify_artist_id' => $id,
        'name' => $name,
        'genres' => $genres,
    ]);
}

function storePlay(
    User $user,
    string $trackId,
    string $playedAt,
    ?int $durationMs = 180000,
    ?string $artistId = null,
): Stream {
    return Stream::query()->create([
        'user_id' => $user->id,
        'spotify_track_id' => $trackId,
        'duration_ms' => $durationMs,
        'artist_id' => $artistId,
        'played_at' => $playedAt,
    ]);
}

it('summarises plays, listening time, and unique tracks and artists', function () {
    $user = User::factory()->create();

    storePlay($user, 'track-a', '2026-11-01 10:00:00', 180000, 'artist-1');
    storePlay($user, 'track-b', '2026-11-01 10:10:00', 240000, 'artist-2');
    storePlay($user, 'track-a', '2026-11-02 09:00:00', 180000, 'artist-1');

    $analytics = app(ListeningAnalyticsService::class)->forUser($user, 'UTC');

    expect($analytics['overview']['plays'])->toBe(3)
        ->and($analytics['overview']['minutes'])->toBe(10)
        ->and($analytics['overview']['unique_tracks'])->toBe(2)
        ->and($analytics['overview']['unique_artists'])->toBe(2)
        ->and($analytics['overview']['average_track_seconds'])->toBe(200)
        ->and($analytics['overview']['first_played_at'])->not->toBeNull()
        ->and($analytics['overview']['last_played_at'])->not->toBeNull();
});

it('weights genres by how often each artist was played', function () {
    $user = User::factory()->create();

    artistWithGenres('artist-1', 'A', ['indie pop', 'synthpop']);
    artistWithGenres('artist-2', 'B', ['indie pop']);

    storePlay($user, 'track-a', '2026-11-01 10:00:00', 180000, 'artist-1');
    storePlay($user, 'track-b', '2026-11-01 10:05:00', 180000, 'artist-1');
    storePlay($user, 'track-c', '2026-11-01 10:10:00', 180000, 'artist-2');

    $genres = app(ListeningAnalyticsService::class)->forUser($user, 'UTC')['top_genres'];

    expect($genres[0])->toBe(['genre' => 'indie pop', 'plays' => 3])
        ->and($genres[1])->toBe(['genre' => 'synthpop', 'plays' => 2]);
});

it('aggregates the most played albums from stored history', function () {
    $user = User::factory()->create();

    $makeTrack = function (string $id, string $name, string $album, string $artist, int $duration): Track {
        return Track::query()->create([
            'spotify_track_id' => $id,
            'name' => $name,
            'artist_id' => 'artist-'.$artist,
            'artist_name' => $artist,
            'album_name' => $album,
            'album_image_url' => "https://images.example/{$album}.jpg",
            'duration_ms' => $duration,
        ]);
    };

    $makeTrack('track-a1', 'A One', 'Album A', 'Artist X', 200000);
    $makeTrack('track-a2', 'A Two', 'Album A', 'Artist X', 100000);
    $makeTrack('track-b1', 'B One', 'Album B', 'Artist Y', 300000);
    $makeTrack('track-c1', 'C One', 'Album C', 'Artist Z', 60000);
    $makeTrack('track-null', 'No Album', '', 'Artist Z', 120000);

    // Album A: 3 plays, 7.5 minutes, 2 unique tracks.
    storePlay($user, 'track-a1', '2026-11-01 10:00:00', null, 'artist-X');
    storePlay($user, 'track-a1', '2026-11-01 10:05:00', 150000, 'artist-X');
    storePlay($user, 'track-a2', '2026-11-01 10:10:00', null, 'artist-X');

    // Albums B and C both have 2 plays; B wins the minutes tie-break.
    storePlay($user, 'track-b1', '2026-11-02 10:00:00', null, 'artist-Y');
    storePlay($user, 'track-b1', '2026-11-02 10:05:00', null, 'artist-Y');
    storePlay($user, 'track-c1', '2026-11-03 10:00:00', null, 'artist-Z');
    storePlay($user, 'track-c1', '2026-11-03 10:05:00', null, 'artist-Z');

    storePlay($user, 'track-null', '2026-11-04 10:00:00', null, 'artist-Z');

    $albums = app(ListeningAnalyticsService::class)->forUser($user, 'UTC')['top_albums'];

    expect($albums)->toHaveCount(3)
        ->and($albums[0])->toBe([
            'album_name' => 'Album A',
            'artist_name' => 'Artist X',
            'cover_url' => 'https://images.example/Album A.jpg',
            'plays' => 3,
            'minutes' => 8,
            'unique_tracks_played' => 2,
        ])
        ->and($albums[1]['album_name'])->toBe('Album B')
        ->and($albums[1]['minutes'])->toBe(10)
        ->and($albums[2]['album_name'])->toBe('Album C')
        ->and($albums[2]['minutes'])->toBe(2);
});

it('returns no top albums when the user has no plays', function () {
    $user = User::factory()->create();

    $analytics = app(ListeningAnalyticsService::class)->forUser($user, 'UTC');

    expect($analytics['top_albums'])->toBe([]);
});

it('buckets the heatmap into the requested local timezone', function () {
    $user = User::factory()->create();

    storePlay($user, 'track-a', '2026-11-08 23:30:00', 180000, 'artist-1');

    $local = Carbon::parse('2026-11-08 23:30:00', 'UTC')->setTimezone('Europe/Berlin');
    $result = app(ListeningAnalyticsService::class)->forUser($user, 'Europe/Berlin');

    expect($result['timezone'])->toBe('Europe/Berlin')
        ->and($result['heatmap']['counts'][$local->isoWeekday() - 1][$local->hour])->toBe(1)
        ->and($result['heatmap']['counts'])->toHaveCount(7)
        ->and($result['heatmap']['max'])->toBe(1);
});

it('calculates current and longest listening streaks', function () {
    $this->travelTo('2026-11-08 12:00:00');

    $user = User::factory()->create();

    foreach (['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-07', '2026-11-08'] as $day) {
        storePlay($user, "track-{$day}", "{$day} 09:00:00", 180000, 'artist-1');
    }

    $streaks = app(ListeningAnalyticsService::class)->forUser($user, 'UTC')['streaks'];

    expect($streaks['current'])->toBe(2)
        ->and($streaks['longest'])->toBe(4);
});

it('reports the share of plays that introduced a new artist', function () {
    $user = User::factory()->create();

    storePlay($user, 'track-a', '2026-11-01 10:00:00', 180000, 'artist-1');
    storePlay($user, 'track-b', '2026-11-01 10:05:00', 180000, 'artist-1');
    storePlay($user, 'track-c', '2026-11-01 10:10:00', 180000, 'artist-2');

    $discovery = app(ListeningAnalyticsService::class)->forUser($user, 'UTC')['discovery'];

    expect($discovery['plays'])->toBe(3)
        ->and($discovery['new_artists'])->toBe(2)
        ->and($discovery['rate'])->toBe(0.6667);
});

it('does not count artists first heard before the discovery window as new', function () {
    $this->travelTo('2026-11-08 12:00:00');

    $user = User::factory()->create();

    // First heard long ago, so not newly discovered this month.
    storePlay($user, 't-old', '2026-09-01 10:00:00', 180000, 'artist-1');
    storePlay($user, 't-recent', '2026-11-05 10:00:00', 180000, 'artist-1');
    storePlay($user, 't-new', '2026-11-06 10:00:00', 180000, 'artist-2');

    $discovery = app(ListeningAnalyticsService::class)->forUser($user, 'UTC')['discovery'];

    expect($discovery['plays'])->toBe(2)
        ->and($discovery['new_artists'])->toBe(1)
        ->and($discovery['rate'])->toBe(0.5);
});

it('returns zeroed analytics for a user without plays', function () {
    $user = User::factory()->create();

    $analytics = app(ListeningAnalyticsService::class)->forUser($user, 'UTC');

    expect($analytics['overview']['plays'])->toBe(0)
        ->and($analytics['overview']['minutes'])->toBe(0)
        ->and($analytics['overview']['average_track_seconds'])->toBeNull()
        ->and($analytics['top_genres'])->toBe([])
        ->and($analytics['discovery']['rate'])->toBe(0.0)
        ->and($analytics['streaks'])->toBe(['current' => 0, 'longest' => 0])
        ->and($analytics['heatmap']['max'])->toBe(0);
});

it('builds weekly artist trends and weekly rank movement', function () {
    $this->travelTo('2026-11-08 12:00:00');

    $user = User::factory()->create();

    artistWithGenres('artist-1', 'Artist One', []);
    artistWithGenres('artist-2', 'Artist Two', []);

    // Current seven days: artist one leads.
    storePlay($user, 't1', '2026-11-03 10:00:00', 180000, 'artist-1');
    storePlay($user, 't2', '2026-11-04 10:00:00', 180000, 'artist-1');
    storePlay($user, 't3', '2026-11-05 10:00:00', 180000, 'artist-1');
    storePlay($user, 't4', '2026-11-07 10:00:00', 180000, 'artist-1');
    storePlay($user, 't5', '2026-11-06 10:00:00', 180000, 'artist-2');

    // Previous seven days: artist two led.
    storePlay($user, 't6', '2026-10-27 10:00:00', 180000, 'artist-2');
    storePlay($user, 't7', '2026-10-28 10:00:00', 180000, 'artist-2');
    storePlay($user, 't8', '2026-10-29 10:00:00', 180000, 'artist-2');
    storePlay($user, 't9', '2026-10-30 10:00:00', 180000, 'artist-1');

    $trends = app(ListeningAnalyticsService::class)->artistTrends($user, 'UTC');

    expect($trends['weeks'])->toHaveCount(12)
        ->and($trends['weeks'][11])->toBe(Carbon::parse('2026-11-08 12:00:00', 'UTC')->startOfWeek()->toDateString())
        ->and($trends['series'])->toHaveCount(2)
        ->and($trends['series'][0]['name'])->toBe('Artist One')
        ->and(array_sum($trends['series'][0]['plays']))->toBe(5)
        ->and($trends['series'][1]['name'])->toBe('Artist Two')
        ->and($trends['movement'][0]['name'])->toBe('Artist One')
        ->and($trends['movement'][0]['change'])->toBe(1)
        ->and($trends['movement'][0]['previous_plays'])->toBe(1)
        ->and($trends['movement'][1]['change'])->toBe(-1)
        ->and($trends['movement'][1]['previous_plays'])->toBe(3);
});

it('marks artists heard for the first time this week as new', function () {
    $this->travelTo('2026-11-08 12:00:00');

    $user = User::factory()->create();

    storePlay($user, 't1', '2026-11-05 10:00:00', 180000, 'artist-1');

    $movement = app(ListeningAnalyticsService::class)->artistTrends($user, 'UTC')['movement'];

    expect($movement[0]['change'])->toBeNull();
});

it('compares the last four weeks with the last year', function () {
    $this->travelTo('2026-11-08 12:00:00');

    $user = User::factory()->create();

    artistWithGenres('artist-1', 'Artist One', []);
    artistWithGenres('artist-2', 'Artist Two', []);

    storePlay($user, 't1', '2026-11-05 10:00:00', 180000, 'artist-1');
    storePlay($user, 't2', '2026-11-06 10:00:00', 180000, 'artist-1');
    storePlay($user, 't3', '2026-11-07 10:00:00', 180000, 'artist-1');
    storePlay($user, 't4', '2026-02-01 10:00:00', 180000, 'artist-2');
    storePlay($user, 't5', '2025-01-01 10:00:00', 180000, 'artist-3');

    $comparison = app(ListeningAnalyticsService::class)->artistComparison($user);

    expect($comparison['recent']['plays'])->toBe(3)
        ->and($comparison['recent']['minutes'])->toBe(9)
        ->and($comparison['recent']['unique_artists'])->toBe(1)
        ->and($comparison['recent']['top_artists'][0]['name'])->toBe('Artist One')
        ->and($comparison['recent']['top_artists'][0]['share'])->toBe(1.0)
        ->and($comparison['year']['plays'])->toBe(4)
        ->and($comparison['year']['unique_artists'])->toBe(2)
        ->and($comparison['year']['top_artists'][0]['name'])->toBe('Artist One')
        ->and($comparison['year']['top_artists'][1]['name'])->toBe('Artist Two')
        ->and($comparison['year']['top_artists'][1]['share'])->toBe(0.25);
});

it('renders the analytics page from stored plays', function () {
    $user = User::factory()->connectedToSpotify()->create();

    artistWithGenres('artist-1', 'A', ['indie pop']);
    storePlay($user, 'track-a', '2026-11-01 10:00:00', 180000, 'artist-1');

    $this->actingAs($user)
        ->get('/analytics?timezone=Europe/Berlin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Analytics')
            ->where('timezone', 'Europe/Berlin')
            ->where('overview.plays', 1)
            ->where('top_genres.0.genre', 'indie pop')
            ->has('heatmap.counts', 7)
            ->where('streaks.longest', 1)
            ->has('trends.weeks', 12)
            ->where('trends.series.0.name', 'A')
            ->where('comparison.recent.plays', 1)
            ->where('comparison.year.plays', 1));
});

it('uses the timezone cookie written by the browser', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withUnencryptedCookie('timezone', 'Europe%2FBerlin')
        ->get('/analytics')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Analytics')
            ->where('timezone', 'Europe/Berlin'));
});

it('falls back to the application timezone when an unknown one is given', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/analytics?timezone=Mars/Olympus')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Analytics')
            ->where('timezone', config('app.timezone')));
});

it('renders the empty analytics state without plays', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/analytics')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Analytics')
            ->where('spotifyConnected', false)
            ->where('overview.plays', 0)
            ->where('top_genres', [])
            ->where('trends.series', [])
            ->where('trends.movement', [])
            ->where('comparison.recent.plays', 0)
            ->where('comparison.year.plays', 0));
});

it('requires authentication for analytics', function () {
    $this->get('/analytics')->assertRedirect('/login');
});

it('stores track duration, primary artist, and artist genres while syncing', function () {
    $user = User::factory()->connectedToSpotify()->create();

    Http::fake([
        'api.spotify.com/v1/me/player/recently-played*' => Http::response([
            'items' => [[
                'track' => [
                    'id' => 'track-a',
                    'duration_ms' => 211000,
                    'artists' => [['id' => 'artist-1', 'name' => 'Artist One']],
                ],
                'played_at' => '2026-11-08T10:00:00.000Z',
                'context' => ['type' => 'playlist'],
            ]],
        ]),
        'api.spotify.com/v1/me/top/artists*' => Http::response([
            'items' => [[
                'id' => 'artist-1',
                'name' => 'Artist One',
                'genres' => ['bedroom pop'],
            ]],
        ]),
    ]);

    $streams = app(StreamSyncService::class);

    expect($streams->sync($user))->toBe(1)
        ->and(Stream::query()->whereBelongsTo($user)->value('duration_ms'))->toBe(211000)
        ->and(Stream::query()->whereBelongsTo($user)->value('artist_id'))->toBe('artist-1')
        ->and(Artist::query()->where('spotify_artist_id', 'artist-1')->value('genres'))->toBe(['bedroom pop']);

    $streams->sync($user);

    Http::assertSentCount(4);
});
