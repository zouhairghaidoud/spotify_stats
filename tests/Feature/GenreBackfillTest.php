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
