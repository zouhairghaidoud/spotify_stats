<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\User;
use Throwable;

/**
 * Backfills missing artist genres from MusicBrainz, most played first.
 */
class GenreBackfillService
{
    public function __construct(private readonly MusicBrainzGenreService $musicbrainz) {}

    /**
     * @return array{checked: int, updated: int, failed: int}
     */
    public function backfill(User $user, int $limit = 50, bool $force = false): array
    {
        $artists = Artist::query()
            ->join('streams', 'streams.artist_id', '=', 'artists.spotify_artist_id')
            ->where('streams.user_id', $user->id)
            ->when(! $force, fn ($query) => $query->whereNull('artists.genres_synced_at'))
            ->select('artists.*')
            ->selectRaw('COUNT(streams.id) as plays')
            ->groupBy('artists.id')
            ->orderByDesc('plays')
            ->orderBy('artists.name')
            ->limit(max(1, $limit))
            ->get();

        $updated = 0;
        $failed = 0;

        foreach ($artists as $artist) {
            try {
                $result = $this->musicbrainz->lookup($artist);

                $artist->forceFill([
                    'genres' => $result['genres'],
                    'musicbrainz_id' => $result['musicbrainz_id'],
                    'genres_synced_at' => now(),
                ])->save();

                $updated++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return [
            'checked' => $artists->count(),
            'updated' => $updated,
            'failed' => $failed,
        ];
    }
}
