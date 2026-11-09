<?php

use App\Models\Artist;
use App\Models\Stream;
use App\Models\Track;
use App\Models\User;
use App\Services\HistoryImportService;
use App\Services\ListeningAnalyticsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<int, array<string, mixed>>  $entries
 */
function extendedHistoryFile(array $entries, string $name = 'Streaming_History_Audio_2024.json'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, json_encode($entries));
}

/**
 * @param  array<int, array<string, mixed>>  $entries
 */
function standardHistoryFile(array $entries, string $name = 'StreamingHistory_music_0.json'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, json_encode($entries));
}

/**
 * @return array<string, mixed>
 */
function extendedPlay(string $trackId, string $playedAt = '2024-03-01T12:00:00Z', int $msPlayed = 210000): array
{
    return [
        'ts' => $playedAt,
        'ms_played' => $msPlayed,
        'master_metadata_track_name' => 'Song '.$trackId,
        'master_metadata_album_artist_name' => 'Artist '.$trackId,
        'master_metadata_album_album_name' => 'Album '.$trackId,
        'spotify_track_uri' => 'spotify:track:'.$trackId,
    ];
}

/**
 * @param  array<string, string>  $files
 */
function zippedHistory(array $files): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'spotify-export-').'.zip';
    $zip = new ZipArchive;

    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();

    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();

    return new UploadedFile($path, 'spotify-export.zip', 'application/zip', null, true);
}

function importPlay(User $user, string $trackId, string $playedAt, int $durationMs = 180000): void
{
    Stream::query()->create([
        'user_id' => $user->id,
        'spotify_track_id' => $trackId,
        'duration_ms' => $durationMs,
        'played_at' => $playedAt,
        'context' => HistoryImportService::CONTEXT,
    ]);
}

it('imports extended streaming history and ignores short or unnamed plays', function () {
    $user = User::factory()->create();
    $trackId = str_repeat('a', 22);

    $result = app(HistoryImportService::class)->import($user, extendedHistoryFile([
        extendedPlay($trackId),
        extendedPlay(str_repeat('b', 22), '2024-03-01T12:05:00Z', 29000),
        [
            'ts' => '2024-03-01T12:10:00Z',
            'ms_played' => 200000,
            'master_metadata_track_name' => null,
            'spotify_track_uri' => 'spotify:track:'.str_repeat('c', 22),
        ],
    ]));

    $stream = Stream::query()->whereBelongsTo($user)->sole();
    $track = Track::query()->where('spotify_track_id', $trackId)->sole();

    expect($result['imported'])->toBe(1)
        ->and($result['skipped'])->toBe(2)
        ->and($result['files'])->toBe(1)
        ->and($stream->spotify_track_id)->toBe($trackId)
        ->and($stream->duration_ms)->toBe(210000)
        ->and($stream->context)->toBe('json_import')
        ->and($stream->artist_id)->toBeNull()
        ->and($track->name)->toBe('Song '.$trackId)
        ->and($track->artist_name)->toBe('Artist '.$trackId)
        ->and($track->album_name)->toBe('Album '.$trackId);
});

it('imports standard account data with deterministic local ids', function () {
    $user = User::factory()->create();

    $result = app(HistoryImportService::class)->import($user, standardHistoryFile([
        [
            'endTime' => '2023-05-04 18:30',
            'msPlayed' => 60000,
            'trackName' => 'Local Song',
            'artistName' => 'Local Artist',
        ],
        [
            'endTime' => '2023-05-04 18:35',
            'msPlayed' => 15000,
            'trackName' => 'Too Short',
            'artistName' => 'Local Artist',
        ],
    ]));

    $stream = Stream::query()->whereBelongsTo($user)->sole();
    $expectedId = 'local_'.substr(md5('local artist|local song'), 0, 22);
    $expectedArtistId = 'local_artist_'.substr(md5('local artist'), 0, 18);

    expect($result['imported'])->toBe(1)
        ->and($stream->spotify_track_id)->toBe($expectedId)
        ->and($stream->artist_id)->toBe($expectedArtistId)
        ->and(Track::query()->where('spotify_track_id', $expectedId)->value('artist_id'))->toBe($expectedArtistId)
        ->and(Artist::query()->where('spotify_artist_id', $expectedArtistId)->value('name'))->toBe('Local Artist');
});

it('imports every json file inside a zip export', function () {
    $user = User::factory()->create();

    $file = zippedHistory([
        'Streaming_History_Audio_1.json' => json_encode([extendedPlay(str_repeat('d', 22))]),
        'Streaming_History_Audio_2.json' => json_encode([extendedPlay(str_repeat('e', 22), '2024-03-02T12:00:00Z')]),
        'readme.txt' => 'not json',
    ]);

    $result = app(HistoryImportService::class)->import($user, $file);

    expect($result['imported'])->toBe(2)
        ->and($result['files'])->toBe(2)
        ->and(Stream::query()->whereBelongsTo($user)->count())->toBe(2);
});

it('never duplicates plays when the same history is uploaded again', function () {
    $user = User::factory()->create();
    $file = extendedHistoryFile([extendedPlay(str_repeat('f', 22))]);
    $imports = app(HistoryImportService::class);

    $first = $imports->import($user, $file);
    $second = $imports->import($user, $file);

    expect($first['imported'])->toBe(1)
        ->and($second['imported'])->toBe(0)
        ->and(Stream::query()->whereBelongsTo($user)->count())->toBe(1);
});

it('imports history through the endpoint and enriches track metadata', function () {
    $user = User::factory()->create();
    $trackId = str_repeat('g', 22);

    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(['access_token' => 'app-token']),
        'api.spotify.com/v1/tracks*' => Http::response([
            'tracks' => [[
                'id' => $trackId,
                'name' => 'Enriched Song',
                'duration_ms' => 181000,
                'artists' => [['id' => 'artist-enriched', 'name' => 'Enriched Artist']],
                'album' => [
                    'name' => 'Enriched Album',
                    'images' => [['url' => 'https://images.example/enriched.jpg']],
                ],
            ]],
        ]),
    ]);

    $this->actingAs($user)
        ->post('/import', ['file' => extendedHistoryFile([extendedPlay($trackId)])])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $track = Track::query()->where('spotify_track_id', $trackId)->sole();
    $stream = Stream::query()->whereBelongsTo($user)->sole();

    expect($track->name)->toBe('Enriched Song')
        ->and($track->artist_id)->toBe('artist-enriched')
        ->and($track->album_image_url)->toBe('https://images.example/enriched.jpg')
        ->and($stream->artist_id)->toBe('artist-enriched')
        ->and(Artist::query()->where('spotify_artist_id', 'artist-enriched')->value('name'))->toBe('Enriched Artist');
});

it('falls back to oembed when the tracks endpoint returns nothing', function () {
    $user = User::factory()->create();
    $trackId = str_repeat('h', 22);

    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(['access_token' => 'app-token']),
        'api.spotify.com/v1/tracks*' => Http::response(['tracks' => []]),
        'open.spotify.com/oembed*' => Http::response([
            'title' => 'Oembed Song',
            'author_name' => 'Oembed Artist',
            'thumbnail_url' => 'https://images.example/oembed.jpg',
        ]),
    ]);

    $this->actingAs($user)
        ->post('/import', ['file' => extendedHistoryFile([extendedPlay($trackId)])])
        ->assertSessionHasNoErrors();

    $track = Track::query()->where('spotify_track_id', $trackId)->sole();
    $artistId = 'local_artist_'.substr(md5('oembed artist'), 0, 18);

    expect($track->name)->toBe('Oembed Song')
        ->and($track->artist_name)->toBe('Oembed Artist')
        ->and($track->artist_id)->toBe($artistId)
        ->and($track->album_image_url)->toBe('https://images.example/oembed.jpg')
        ->and(Stream::query()->whereBelongsTo($user)->value('artist_id'))->toBe($artistId);
});

it('rejects invalid uploads', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/import', ['file' => UploadedFile::fake()->create('history.txt', 1, 'text/plain')])
        ->assertSessionHasErrors('file');

    $this->actingAs($user)
        ->post('/import', ['file' => UploadedFile::fake()->createWithContent('broken.json', '{not json')])
        ->assertSessionHasErrors('file');
});

it('requires authentication to import history', function () {
    $this->post('/import')->assertRedirect('/login');
});

it('reports exact play counts and minutes in the song leaderboard', function () {
    $user = User::factory()->create();

    Track::query()->create([
        'spotify_track_id' => 'track-a',
        'name' => 'Big Hit',
        'artist_id' => 'artist-famous',
        'artist_name' => 'Famous Artist',
        'album_image_url' => 'https://images.example/big-hit.jpg',
        'duration_ms' => 200000,
    ]);
    Track::query()->create([
        'spotify_track_id' => 'track-b',
        'name' => 'Deep Cut',
        'artist_name' => 'Other Artist',
        'duration_ms' => 120000,
    ]);

    importPlay($user, 'track-a', '2026-11-01 10:00:00', 200000);
    importPlay($user, 'track-a', '2026-11-02 10:00:00', 200000);
    importPlay($user, 'track-b', '2026-11-03 10:00:00', 120000);

    $leaderboard = app(ListeningAnalyticsService::class)->forUser($user)['tracks_leaderboard'];

    expect($leaderboard)->toHaveCount(2)
        ->and($leaderboard[0]['spotify_track_id'])->toBe('track-a')
        ->and($leaderboard[0]['name'])->toBe('Big Hit')
        ->and($leaderboard[0]['artist_id'])->toBe('artist-famous')
        ->and($leaderboard[0]['plays'])->toBe(2)
        ->and($leaderboard[0]['minutes'])->toBe(7)
        ->and($leaderboard[0]['album_image_url'])->toBe('https://images.example/big-hit.jpg');
});

it('filters the song leaderboard by title or artist', function () {
    $user = User::factory()->create();

    Track::query()->create([
        'spotify_track_id' => 'track-a',
        'name' => 'Big Hit',
        'artist_name' => 'Famous Artist',
    ]);
    Track::query()->create([
        'spotify_track_id' => 'track-b',
        'name' => 'Deep Cut',
        'artist_name' => 'Other Artist',
    ]);

    importPlay($user, 'track-a', '2026-11-01 10:00:00');
    importPlay($user, 'track-b', '2026-11-02 10:00:00');

    $analytics = app(ListeningAnalyticsService::class);

    $byTitle = $analytics->forUser($user, null, 'big')['tracks_leaderboard'];
    $byArtist = $analytics->forUser($user, null, 'Other')['tracks_leaderboard'];

    expect($byTitle)->toHaveCount(1)
        ->and($byTitle[0]['spotify_track_id'])->toBe('track-a')
        ->and($byArtist)->toHaveCount(1)
        ->and($byArtist[0]['spotify_track_id'])->toBe('track-b');
});

it('renders the leaderboard and search term on the analytics page', function () {
    $user = User::factory()->create();

    Track::query()->create([
        'spotify_track_id' => 'track-a',
        'name' => 'Big Hit',
        'artist_name' => 'Famous Artist',
    ]);

    importPlay($user, 'track-a', '2026-11-01 10:00:00', 200000);

    $this->actingAs($user)
        ->get('/analytics?search=Big')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Analytics')
            ->where('search', 'Big')
            ->where('tracks_leaderboard.0.name', 'Big Hit')
            ->where('tracks_leaderboard.0.plays', 1));
});
