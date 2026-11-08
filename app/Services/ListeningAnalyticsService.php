<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Builds listening analytics from the persisted streams.
 *
 * All figures are computed from the database only, so opening the analytics
 * page never depends on the Spotify API being available.
 */
class ListeningAnalyticsService
{
    private const TOP_GENRE_LIMIT = 8;

    /**
     * @return array{
     *     timezone: string,
     *     overview: array{
     *         plays: int,
     *         minutes: int,
     *         unique_tracks: int,
     *         unique_artists: int,
     *         average_track_seconds: int|null,
     *         first_played_at: string|null,
     *         last_played_at: string|null
     *     },
     *     top_genres: array<int, array{genre: string, plays: int}>,
     *     heatmap: array{counts: array<int, array<int, int>>, max: int},
     *     discovery: array{new_artists: int, plays: int, rate: float},
     *     streaks: array{current: int, longest: int}
     * }
     */
    public function forUser(User $user, ?string $timezone = null): array
    {
        $timezone = $this->timezone($timezone);

        $totals = $user->streams()
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('COALESCE(SUM(duration_ms), 0) as duration_ms')
            ->selectRaw('AVG(duration_ms) as average_duration_ms')
            ->selectRaw('COUNT(DISTINCT spotify_track_id) as tracks')
            ->selectRaw('COUNT(DISTINCT artist_id) as artists')
            ->toBase()
            ->first();

        $plays = (int) ($totals->plays ?? 0);
        $uniqueArtists = (int) ($totals->artists ?? 0);
        $durationMs = (int) ($totals->duration_ms ?? 0);
        $averageDurationMs = $totals->average_duration_ms ?? null;

        [$heatmap, $days] = $this->heatmapAndDays($user, $timezone);

        return [
            'timezone' => $timezone,
            'overview' => [
                'plays' => $plays,
                'minutes' => intdiv($durationMs, 60000),
                'unique_tracks' => (int) ($totals->tracks ?? 0),
                'unique_artists' => $uniqueArtists,
                'average_track_seconds' => $averageDurationMs === null
                    ? null
                    : (int) round((float) $averageDurationMs / 1000),
                'first_played_at' => $this->timestamp($user->streams()->min('played_at')),
                'last_played_at' => $this->timestamp($user->streams()->max('played_at')),
            ],
            'top_genres' => $this->topGenres($user),
            'heatmap' => [
                'counts' => $heatmap,
                'max' => $this->heatmapMax($heatmap),
            ],
            'discovery' => [
                'new_artists' => $uniqueArtists,
                'plays' => $plays,
                'rate' => $plays > 0 ? round($uniqueArtists / $plays, 4) : 0.0,
            ],
            'streaks' => $this->streaks($days, $timezone),
        ];
    }

    /**
     * Count plays per local weekday (Monday first) and hour of day.
     *
     * @return array{0: array<int, array<int, int>>, 1: array<string, true>}
     */
    private function heatmapAndDays(User $user, string $timezone): array
    {
        $heatmap = array_fill(0, 7, array_fill(0, 24, 0));
        $days = [];

        $user->streams()
            ->orderBy('played_at')
            ->select(['played_at'])
            ->cursor()
            ->each(function (Stream $stream) use (&$heatmap, &$days, $timezone): void {
                $playedAt = $stream->played_at->copy()->setTimezone($timezone);

                $heatmap[$playedAt->isoWeekday() - 1][$playedAt->hour]++;
                $days[$playedAt->toDateString()] = true;
            });

        return [$heatmap, $days];
    }

    /**
     * Weight artist genres by how often the artist was played.
     *
     * @return array<int, array{genre: string, plays: int}>
     */
    private function topGenres(User $user): array
    {
        $artistPlays = $user->streams()
            ->whereNotNull('artist_id')
            ->selectRaw('artist_id, COUNT(*) as plays')
            ->groupBy('artist_id')
            ->toBase()
            ->get()
            ->pluck('plays', 'artist_id');

        if ($artistPlays->isEmpty()) {
            return [];
        }

        $genres = Artist::query()
            ->whereIn('spotify_artist_id', $artistPlays->keys()->all())
            ->get(['spotify_artist_id', 'genres'])
            ->mapWithKeys(fn (Artist $artist): array => [
                (string) $artist->spotify_artist_id => $artist->genres ?? [],
            ]);

        $counts = [];

        foreach ($artistPlays as $artistId => $playCount) {
            foreach (array_unique($genres->get((string) $artistId, [])) as $genre) {
                $counts[$genre] = ($counts[$genre] ?? 0) + (int) $playCount;
            }
        }

        arsort($counts);

        $top = [];

        foreach (array_slice($counts, 0, self::TOP_GENRE_LIMIT, preserve_keys: true) as $genre => $playCount) {
            $top[] = ['genre' => (string) $genre, 'plays' => $playCount];
        }

        return $top;
    }

    /**
     * @param  array<string, true>  $days
     * @return array{current: int, longest: int}
     */
    private function streaks(array $days, string $timezone): array
    {
        $longest = 0;
        $run = 0;
        $previous = null;

        foreach (array_keys($days) as $day) {
            $date = Carbon::parse($day, $timezone)->startOfDay();

            $run = $previous !== null && $previous->copy()->addDay()->isSameDay($date)
                ? $run + 1
                : 1;
            $longest = max($longest, $run);
            $previous = $date;
        }

        $today = Carbon::now($timezone)->startOfDay();
        $cursor = isset($days[$today->toDateString()])
            ? $today
            : (isset($days[$today->copy()->subDay()->toDateString()]) ? $today->copy()->subDay() : null);

        $current = 0;

        while ($cursor !== null && isset($days[$cursor->toDateString()])) {
            $current++;
            $cursor = $cursor->copy()->subDay();
        }

        return ['current' => $current, 'longest' => $longest];
    }

    /**
     * @param  array<int, array<int, int>>  $heatmap
     */
    private function heatmapMax(array $heatmap): int
    {
        $max = 0;

        foreach ($heatmap as $row) {
            $max = max($max, ...$row);
        }

        return $max;
    }

    private function timezone(?string $timezone): string
    {
        if (is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }

    private function timestamp(mixed $value): ?string
    {
        return filled($value) ? Carbon::parse($value)->toIso8601String() : null;
    }
}
