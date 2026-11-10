<?php

use App\Models\Stream;
use App\Models\Track;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function detailPlay(User $user, string $trackId, string $playedAt, ?int $durationMs = 180000, ?string $artistId = null): Stream
{
    return Stream::query()->create([
        'user_id' => $user->id,
        'spotify_track_id' => $trackId,
        'duration_ms' => $durationMs,
        'artist_id' => $artistId,
        'played_at' => $playedAt,
    ]);
}

it('shows the stored history for a single album', function () {
    $user = User::factory()->create();

    Track::query()->create([
        'spotify_track_id' => 'track-a',
        'name' => 'Track A',
        'artist_id' => 'artist-1',
        'artist_name' => 'Artist One',
        'album_name' => 'Album One',
        'album_image_url' => 'https://images.example/album-one.jpg',
        'duration_ms' => 180000,
    ]);

    Track::query()->create([
        'spotify_track_id' => 'track-b',
        'name' => 'Track B',
        'artist_id' => 'artist-1',
        'artist_name' => 'Artist One',
        'album_name' => 'Album One',
        'album_image_url' => 'https://images.example/album-one.jpg',
        'duration_ms' => 120000,
    ]);

    detailPlay($user, 'track-a', '2026-11-01 10:00:00', 180000, 'artist-1');
    detailPlay($user, 'track-a', '2026-10-01 10:00:00', 180000, 'artist-1');
    detailPlay($user, 'track-b', '2026-11-02 10:00:00', 120000, 'artist-1');

    $this->actingAs($user)
        ->get('/albums/Album One?artist=Artist One')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('AlbumDetail')
            ->where('album.album_name', 'Album One')
            ->where('album.artist_name', 'Artist One')
            ->where('album.cover_url', 'https://images.example/album-one.jpg')
            ->where('album.overview.plays', 3)
            ->where('album.overview.minutes', 8)
            ->where('album.overview.unique_tracks', 2)
            ->has('album.tracks', 2)
            ->where('album.tracks.0.spotify_track_id', 'track-a')
            ->where('album.tracks.0.plays', 2)
            ->has('album.timeline', 2));
});

it('shows the stored history for a single artist', function () {
    $user = User::factory()->create();

    Track::query()->create([
        'spotify_track_id' => 'track-a',
        'name' => 'Track A',
        'artist_id' => 'artist-1',
        'artist_name' => 'Artist One',
        'album_name' => 'Album One',
        'duration_ms' => 180000,
    ]);

    Track::query()->create([
        'spotify_track_id' => 'track-b',
        'name' => 'Track B',
        'artist_id' => 'artist-1',
        'artist_name' => 'Artist One',
        'album_name' => 'Album Two',
        'duration_ms' => 240000,
    ]);

    detailPlay($user, 'track-a', '2026-11-01 10:00:00', 180000, 'artist-1');
    detailPlay($user, 'track-b', '2026-11-02 10:00:00', 240000, 'artist-1');

    $this->actingAs($user)
        ->get('/artists/artist-1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ArtistDetail')
            ->where('artist.artist_id', 'artist-1')
            ->where('artist.name', 'Artist One')
            ->where('artist.overview.plays', 2)
            ->where('artist.overview.minutes', 7)
            ->where('artist.overview.unique_tracks', 2)
            ->where('artist.overview.unique_albums', 2)
            ->has('artist.tracks', 2)
            ->has('artist.albums', 2)
            ->has('artist.timeline', 1));
});

it('returns not found for unknown albums and artists', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/albums/Unknown Album')
        ->assertNotFound();

    $this->actingAs($user)
        ->get('/artists/unknown-artist')
        ->assertNotFound();
});

it('requires authentication for detail pages', function () {
    $this->get('/albums/Album One')->assertRedirect('/login');
    $this->get('/artists/artist-1')->assertRedirect('/login');
});
