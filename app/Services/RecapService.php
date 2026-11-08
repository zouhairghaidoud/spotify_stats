<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Track;
use App\Models\User;

/**
 * Builds the wrapped-style recap: top tracks, top artist, minutes, genres,
 * and the most active hour of the day.
 */
class RecapService
{
    private const TOP_TRACKS = 5;

    private const TOP_GENRES = 3;

    public function __construct(private readonly ListeningAnalyticsService $analytics) {}

    /**
     * @return array{
     *     timezone: string,
     *     period: array{from: string|null, to: string|null},
     *     overview: array{plays: int, minutes: int, unique_artists: int, unique_tracks: int},
     *     top_tracks: array<int, array{spotify_track_id: string, name: string, artist_name: string|null, album_name: string|null, cover_url: string|null, duration_ms: int|null, plays: int}>,
     *     top_artist: array{artist_id: string, name: string, plays: int, share: float}|null,
     *     top_genres: array<int, array{genre: string, plays: int}>,
     *     peak_hour: array{hour: int, plays: int}|null
     * }
     */
    public function forUser(User $user, ?string $timezone = null): array
    {
        $analytics = $this->analytics->forUser($user, $timezone);

        return [
            'timezone' => $analytics['timezone'],
            'period' => [
                'from' => $analytics['overview']['first_played_at'],
                'to' => $analytics['overview']['last_played_at'],
            ],
            'overview' => [
                'plays' => $analytics['overview']['plays'],
                'minutes' => $analytics['overview']['minutes'],
                'unique_artists' => $analytics['overview']['unique_artists'],
                'unique_tracks' => $analytics['overview']['unique_tracks'],
            ],
            'top_tracks' => $this->topTracks($user),
            'top_artist' => $this->topArtist($user),
            'top_genres' => array_slice($analytics['top_genres'], 0, self::TOP_GENRES),
            'peak_hour' => $this->peakHour($analytics['heatmap']['counts']),
        ];
    }

    /**
     * @return array<int, array{spotify_track_id: string, name: string, artist_name: string|null, album_name: string|null, cover_url: string|null, duration_ms: int|null, plays: int}>
     */
    private function topTracks(User $user): array
    {
        $rows = $user->streams()
            ->selectRaw('spotify_track_id, COUNT(*) as plays')
            ->groupBy('spotify_track_id')
            ->orderByDesc('plays')
            ->orderBy('spotify_track_id')
            ->limit(self::TOP_TRACKS)
            ->toBase()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $tracks = Track::query()
            ->whereIn('spotify_track_id', $rows->pluck('spotify_track_id')->all())
            ->get()
            ->keyBy('spotify_track_id');

        return $rows
            ->map(function (object $row) use ($tracks): array {
                $track = $tracks->get($row->spotify_track_id);

                return [
                    'spotify_track_id' => (string) $row->spotify_track_id,
                    'name' => $track->name ?? 'Unknown track',
                    'artist_name' => $track->artist_name ?? null,
                    'album_name' => $track->album_name ?? null,
                    'cover_url' => $track->album_image_url ?? null,
                    'duration_ms' => $track->duration_ms ?? null,
                    'plays' => (int) $row->plays,
                ];
            })
            ->all();
    }

    /**
     * @return array{artist_id: string, name: string, plays: int, share: float}|null
     */
    private function topArtist(User $user): ?array
    {
        $row = $user->streams()
            ->whereNotNull('artist_id')
            ->selectRaw('artist_id, COUNT(*) as plays')
            ->groupBy('artist_id')
            ->orderByDesc('plays')
            ->orderBy('artist_id')
            ->toBase()
            ->first();

        if ($row === null) {
            return null;
        }

        $artistId = (string) $row->artist_id;

        $name = Artist::query()->where('spotify_artist_id', $artistId)->value('name')
            ?? Track::query()->where('artist_id', $artistId)->value('artist_name')
            ?? 'Unknown artist';

        $totalArtistsPlays = (int) $user->streams()->whereNotNull('artist_id')->count();

        return [
            'artist_id' => $artistId,
            'name' => $name,
            'plays' => (int) $row->plays,
            'share' => $totalArtistsPlays > 0
                ? round((int) $row->plays / $totalArtistsPlays, 4)
                : 0.0,
        ];
    }

    /**
     * @param  array<int, array<int, int>>  $heatmap
     * @return array{hour: int, plays: int}|null
     */
    private function peakHour(array $heatmap): ?array
    {
        $totals = array_fill(0, 24, 0);

        foreach ($heatmap as $row) {
            foreach ($row as $hour => $plays) {
                $totals[$hour] = ($totals[$hour] ?? 0) + $plays;
            }
        }

        $max = max($totals);

        if ($max === 0) {
            return null;
        }

        return [
            'hour' => (int) array_search($max, $totals, true),
            'plays' => $max,
        ];
    }
}
