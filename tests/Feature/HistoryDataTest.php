<?php

use App\Models\HistoryImport;
use App\Models\Stream;
use App\Models\Track;
use App\Models\User;

function dataPlay(User $user, string $trackId, string $playedAt, ?string $context): Stream
{
    return Stream::query()->create([
        'user_id' => $user->id,
        'spotify_track_id' => $trackId,
        'duration_ms' => 180000,
        'played_at' => $playedAt,
        'context' => $context,
    ]);
}

it('exports the listening history as csv', function () {
    $user = User::factory()->create();

    Track::query()->create([
        'spotify_track_id' => 'track-a',
        'name' => 'Track A',
        'artist_name' => 'Artist One',
        'album_name' => 'Album One',
    ]);

    dataPlay($user, 'track-a', '2026-11-01 10:00:00', 'json_import');
    dataPlay($user, 'track-a', '2026-11-02 10:00:00', null);

    $response = $this->actingAs($user)->get('/history/export');

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $content = $response->streamedContent();

    expect($content)->toContain('played_at,track_name,artist_name,album_name,duration_ms,spotify_track_id,context')
        ->toContain('Track A')
        ->toContain('Artist One')
        ->toContain('Album One')
        ->toContain('json_import')
        ->toContain('2026-11-01 10:00:00');
});

it('deletes only imported plays and their import records', function () {
    $user = User::factory()->create();

    Track::query()->create([
        'spotify_track_id' => 'track-a',
        'name' => 'Track A',
    ]);

    dataPlay($user, 'track-a', '2026-11-01 10:00:00', 'json_import');
    dataPlay($user, 'track-a', '2026-11-02 10:00:00', 'json_import');
    dataPlay($user, 'track-a', '2026-11-03 10:00:00', null);

    $user->historyImports()->create([
        'status' => HistoryImport::STATUS_COMPLETED,
        'original_name' => 'history.json',
        'stored_path' => 'imports/history.json',
    ]);

    $this->actingAs($user)
        ->delete('/history/imported')
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Stream::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(Stream::query()->whereBelongsTo($user)->value('context'))->toBeNull()
        ->and($user->historyImports()->count())->toBe(0)
        ->and(Track::query()->where('spotify_track_id', 'track-a')->exists())->toBeTrue();
});

it('requires authentication for history data tools', function () {
    $this->get('/history/export')->assertRedirect('/login');
    $this->delete('/history/imported')->assertRedirect('/login');
});
