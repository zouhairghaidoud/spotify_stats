<?php

use App\Jobs\BackfillGenresJob;
use App\Models\Artist;
use App\Models\Stream;
use App\Models\User;
use App\Services\GenreBackfillService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('services.musicbrainz.delay_ms', 0);
    config()->set('services.musicbrainz.retry_sleep_seconds', 0);
});

function genreArtist(string $id, string $name, ?array $genres = null): Artist
{
    return Artist::query()->create([
        'spotify_artist_id' => $id,
        'name' => $name,
        'genres' => $genres,
    ]);
}

function genrePlay(User $user, string $artistId, int $plays = 1): void
{
    for ($index = 0; $index < $plays; $index++) {
        Stream::query()->create([
            'user_id' => $user->id,
            'spotify_track_id' => 'track-'.$artistId.'-'.$index,
            'duration_ms' => 180000,
            'artist_id' => $artistId,
            'played_at' => now()->subMinutes($index),
        ]);
    }
}

it('backfills genres from musicbrainz using the spotify artist link', function () {
    Http::fake([
        'musicbrainz.org/ws/2/url*' => Http::response([
            'relations' => [['artist' => ['id' => 'mbid-1']]],
        ]),
        'musicbrainz.org/ws/2/artist/mbid-1*' => Http::response([
            'genres' => [
                ['name' => 'Art Pop', 'count' => 2],
                ['name' => 'Indie Rock', 'count' => 10],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $artist = genreArtist('spotify-artist-1', 'Artist One');
    genrePlay($user, 'spotify-artist-1');

    $result = app(GenreBackfillService::class)->backfill($user);

    $artist->refresh();

    expect($result)->toBe(['checked' => 1, 'updated' => 1, 'failed' => 0])
        ->and($artist->genres)->toBe(['indie rock', 'art pop'])
        ->and($artist->musicbrainz_id)->toBe('mbid-1')
        ->and($artist->genres_synced_at)->not->toBeNull();

    Http::assertSentCount(2);
});

it('falls back to a name search when no spotify link exists', function () {
    Http::fake([
        'musicbrainz.org/ws/2/url*' => Http::response(['relations' => []]),
        'musicbrainz.org/ws/2/artist?*' => Http::response([
            'artists' => [['id' => 'mbid-2']],
        ]),
        'musicbrainz.org/ws/2/artist/mbid-2*' => Http::response([
            'genres' => [],
            'tags' => [
                ['name' => 'Dream Pop', 'count' => 5],
                ['name' => 'Shoegaze', 'count' => 3],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $artist = genreArtist('spotify-artist-2', 'Artist Two');
    genrePlay($user, 'spotify-artist-2');

    app(GenreBackfillService::class)->backfill($user);

    expect($artist->refresh()->genres)->toBe(['dream pop', 'shoegaze'])
        ->and($artist->musicbrainz_id)->toBe('mbid-2');
});

it('treats a missing musicbrainz url as not found and falls back to search', function () {
    Http::fake([
        'musicbrainz.org/ws/2/url*' => Http::response(['error' => 'Not Found'], 404),
        'musicbrainz.org/ws/2/artist?*' => Http::response([
            'artists' => [['id' => 'mbid-4']],
        ]),
        'musicbrainz.org/ws/2/artist/mbid-4*' => Http::response([
            'genres' => [['name' => 'Synthpop', 'count' => 6]],
        ]),
    ]);

    $user = User::factory()->create();
    $artist = genreArtist('spotify-artist-4', 'Artist Four');
    genrePlay($user, 'spotify-artist-4');

    $result = app(GenreBackfillService::class)->backfill($user);

    expect($result['failed'])->toBe(0)
        ->and($artist->refresh()->genres)->toBe(['synthpop']);
});

it('prefers an exact artist name match over an unrelated search hit', function () {
    Http::fake([
        'musicbrainz.org/ws/2/url*' => Http::response(['error' => 'Not Found'], 404),
        'musicbrainz.org/ws/2/artist?*' => Http::response([
            'artists' => [
                ['id' => 'wrong-mbid', 'name' => 'DJ Madd'],
                ['id' => 'right-mbid', 'name' => 'Madd'],
            ],
        ]),
        'musicbrainz.org/ws/2/artist/right-mbid*' => Http::response([
            'genres' => [['name' => 'Moroccan Rap', 'count' => 4]],
        ]),
    ]);

    $user = User::factory()->create();
    $artist = genreArtist('spotify-artist-6', 'Madd');
    genrePlay($user, 'spotify-artist-6');

    app(GenreBackfillService::class)->backfill($user);

    expect($artist->refresh()->genres)->toBe(['moroccan rap'])
        ->and($artist->musicbrainz_id)->toBe('right-mbid');
});

it('retries a busy musicbrainz response before failing', function () {
    Http::fake([
        'musicbrainz.org/ws/2/url*' => Http::sequence()
            ->push(['error' => 'busy'], 503)
            ->push(['relations' => [['artist' => ['id' => 'mbid-5']]]]),
        'musicbrainz.org/ws/2/artist/mbid-5*' => Http::response([
            'genres' => [['name' => 'Downtempo', 'count' => 3]],
        ]),
    ]);

    $user = User::factory()->create();
    $artist = genreArtist('spotify-artist-5', 'Artist Five');
    genrePlay($user, 'spotify-artist-5');

    $result = app(GenreBackfillService::class)->backfill($user);

    expect($result['failed'])->toBe(0)
        ->and($artist->refresh()->genres)->toBe(['downtempo']);
});

it('only rechecks artists that were already synced when forced', function () {
    Http::fake([
        'musicbrainz.org/ws/2/url*' => Http::response([
            'relations' => [['artist' => ['id' => 'mbid-3']]],
        ]),
        'musicbrainz.org/ws/2/artist/mbid-3*' => Http::response([
            'genres' => [['name' => 'Ambient', 'count' => 4]],
        ]),
    ]);

    $user = User::factory()->create();
    $artist = genreArtist('spotify-artist-3', 'Artist Three', ['old genre']);
    $artist->forceFill([
        'musicbrainz_id' => 'mbid-3',
        'genres_synced_at' => now()->subDay(),
    ])->save();
    genrePlay($user, 'spotify-artist-3');

    $service = app(GenreBackfillService::class);

    expect($service->backfill($user)['checked'])->toBe(0)
        ->and($artist->refresh()->genres)->toBe(['old genre']);

    expect($service->backfill($user, force: true)['updated'])->toBe(1)
        ->and($artist->refresh()->genres)->toBe(['ambient']);
});

it('falls back to last.fm when musicbrainz returns no genres', function () {
    config()->set('services.lastfm.api_key', 'test-key');
    config()->set('services.lastfm.base_url', 'https://ws.audioscrobbler.com/2.0');

    Http::fake([
        'musicbrainz.org/ws/2/url*' => Http::response([
            'relations' => [['artist' => ['id' => 'mbid-7']]],
        ]),
        'musicbrainz.org/ws/2/artist/mbid-7*' => Http::response([
            'genres' => [],
            'tags' => [],
        ]),
        'ws.audioscrobbler.com/*' => Http::response([
            'toptags' => [
                'tag' => [
                    ['name' => 'Moroccan Rap', 'count' => 100],
                    ['name' => 'seen live', 'count' => 80],
                    ['name' => 'Trap', 'count' => 40],
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $artist = genreArtist('spotify-artist-7', 'Artist Seven');
    genrePlay($user, 'spotify-artist-7');

    $result = app(GenreBackfillService::class)->backfill($user);

    expect($result)->toBe(['checked' => 1, 'updated' => 1, 'failed' => 0])
        ->and($artist->refresh()->genres)->toBe(['moroccan rap', 'trap'])
        ->and($artist->musicbrainz_id)->toBe('mbid-7');
});

it('keeps manually edited genres even when a backfill is forced', function () {
    Http::fake();

    $user = User::factory()->create();
    $artist = genreArtist('spotify-artist-8', 'Artist Eight', ['custom genre']);
    $artist->forceFill(['genres_overridden' => true])->save();
    genrePlay($user, 'spotify-artist-8');

    $result = app(GenreBackfillService::class)->backfill($user, force: true);

    expect($result['checked'])->toBe(0)
        ->and($artist->refresh()->genres)->toBe(['custom genre']);
});

it('lets the user override an artist genre list', function () {
    $user = User::factory()->create();
    $artist = genreArtist('spotify-artist-9', 'Artist Nine', ['old genre']);
    genrePlay($user, 'spotify-artist-9');

    $this->actingAs($user)
        ->post('/artists/spotify-artist-9/genres', [
            'genres' => ['Hip Hop', 'hip hop', 'Trap'],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $artist->refresh();

    expect($artist->genres)->toBe(['hip hop', 'trap'])
        ->and($artist->genres_overridden)->toBeTrue()
        ->and($artist->genres_synced_at)->not->toBeNull();
});

it('rejects genre overrides for artists the user has not played', function () {
    $user = User::factory()->create();
    genreArtist('spotify-artist-10', 'Artist Ten');

    $this->actingAs($user)
        ->post('/artists/spotify-artist-10/genres', ['genres' => ['trap']])
        ->assertNotFound();
});

it('requires authentication to override artist genres', function () {
    $this->post('/artists/spotify-artist-11/genres', ['genres' => ['trap']])
        ->assertRedirect('/login');
});

it('queues a genre backfill from the analytics page', function () {
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/genres/backfill')
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(BackfillGenresJob::class, fn (BackfillGenresJob $job): bool => $job->user->is($user));
});

it('requires authentication to backfill genres', function () {
    $this->post('/genres/backfill')->assertRedirect('/login');
});
