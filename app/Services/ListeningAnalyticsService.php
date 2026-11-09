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

    private const TREND_WEEKS = 12;

    private const TREND_ARTISTS = 6;

    private const MOVEMENT_ARTISTS = 5;

    private const COMPARISON_ARTISTS = 5;

    /**
     * Window used to decide whether an artist was recently discovered.
     */
    private const DISCOVERY_DAYS = 28;

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
            'discovery' => $this->discovery($user),
            'streaks' => $this->streaks($days, $timezone),
        ];
    }

    /**
     * Share of recent plays that introduced an artist for the first time.
     *
     * Only artists whose very first stored play happened inside the discovery
     * window count, so a long-time favorite is never reported as new again.
     *
     * @return array{new_artists: int, plays: int, rate: float}
     */
    private function discovery(User $user): array
    {
        $windowStart = now()->subDays(self::DISCOVERY_DAYS);

        $plays = (int) $user->streams()
            ->where('played_at', '>=', $windowStart)
            ->count();

        $newArtists = $user->streams()
            ->whereNotNull('artist_id')
            ->selectRaw('artist_id, MIN(played_at) as first_played')
            ->groupBy('artist_id')
            ->havingRaw('MIN(played_at) >= ?', [$windowStart->copy()->utc()->format('Y-m-d H:i:s')])
            ->toBase()
            ->get()
            ->count();

        return [
            'new_artists' => $newArtists,
            'plays' => $plays,
            'rate' => $plays > 0 ? round($newArtists / $plays, 4) : 0.0,
        ];
    }

    /**
     * Weekly play counts for the user's most played artists, plus how their
     * ranking moved over the last week compared with the week before.
     *
     * @return array{
     *     weeks: array<int, string>,
     *     series: array<int, array{artist_id: string, name: string, plays: array<int, int>}>,
     *     movement: array<int, array{artist_id: string, name: string, plays: int, previous_plays: int, rank: int, change: int|null}>
     * }
     */
    public function artistTrends(User $user, ?string $timezone = null): array
    {
        $timezone = $this->timezone($timezone);
        $now = Carbon::now($timezone);
        $firstWeek = $now->copy()->startOfWeek()->subWeeks(self::TREND_WEEKS - 1);

        $weeks = [];

        for ($index = 0; $index < self::TREND_WEEKS; $index++) {
            $weeks[] = $firstWeek->copy()->addWeeks($index)->toDateString();
        }

        $weekIndex = array_flip($weeks);
        $counts = [];
        $totals = [];

        $user->streams()
            ->where('played_at', '>=', $firstWeek->copy()->setTimezone('UTC'))
            ->whereNotNull('artist_id')
            ->orderBy('played_at')
            ->select(['played_at', 'artist_id'])
            ->cursor()
            ->each(function (Stream $stream) use (&$counts, &$totals, $weekIndex, $timezone): void {
                $week = $stream->played_at->copy()->setTimezone($timezone)->startOfWeek()->toDateString();

                if (! isset($weekIndex[$week])) {
                    return;
                }

                $artistId = (string) $stream->artist_id;
                $index = $weekIndex[$week];

                $counts[$artistId][$index] = ($counts[$artistId][$index] ?? 0) + 1;
                $totals[$artistId] = ($totals[$artistId] ?? 0) + 1;
            });

        arsort($totals);

        $topArtists = array_slice(array_keys($totals), 0, self::TREND_ARTISTS);
        $names = $this->artistNames($topArtists);
        $series = [];

        foreach ($topArtists as $artistId) {
            $plays = [];

            for ($index = 0; $index < self::TREND_WEEKS; $index++) {
                $plays[] = $counts[$artistId][$index] ?? 0;
            }

            $series[] = [
                'artist_id' => (string) $artistId,
                'name' => $names[$artistId] ?? 'Unknown artist',
                'plays' => $plays,
            ];
        }

        return [
            'weeks' => $weeks,
            'series' => $series,
            'movement' => $this->rankMovement($user),
        ];
    }

    /**
     * Compare the top artists and totals of the last four weeks with the last year.
     *
     * @return array{
     *     recent: array{plays: int, minutes: int, unique_artists: int, top_artists: array<int, array{artist_id: string, name: string, plays: int, share: float}>},
     *     year: array{plays: int, minutes: int, unique_artists: int, top_artists: array<int, array{artist_id: string, name: string, plays: int, share: float}>}
     * }
     */
    public function artistComparison(User $user): array
    {
        $now = now();

        return [
            'recent' => $this->windowSummary($user, $now->copy()->subDays(28)),
            'year' => $this->windowSummary($user, $now->copy()->subDays(365)),
        ];
    }

    /**
     * @return array{plays: int, minutes: int, unique_artists: int, top_artists: array<int, array{artist_id: string, name: string, plays: int, share: float}>}
     */
    private function windowSummary(User $user, Carbon $from): array
    {
        $totals = $user->streams()
            ->where('played_at', '>=', $from)
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('COALESCE(SUM(duration_ms), 0) as duration_ms')
            ->selectRaw('COUNT(DISTINCT artist_id) as artists')
            ->toBase()
            ->first();

        $plays = (int) ($totals->plays ?? 0);
        $durationMs = (int) ($totals->duration_ms ?? 0);
        $uniqueArtists = (int) ($totals->artists ?? 0);

        $rows = $user->streams()
            ->where('played_at', '>=', $from)
            ->whereNotNull('artist_id')
            ->selectRaw('artist_id, COUNT(*) as plays')
            ->groupBy('artist_id')
            ->toBase()
            ->get()
            ->sortByDesc('plays')
            ->take(self::COMPARISON_ARTISTS)
            ->values();

        $names = $this->artistNames($rows->pluck('artist_id')->all());

        return [
            'plays' => $plays,
            'minutes' => intdiv($durationMs, 60000),
            'unique_artists' => $uniqueArtists,
            'top_artists' => $rows
                ->map(fn (object $row): array => [
                    'artist_id' => (string) $row->artist_id,
                    'name' => $names[$row->artist_id] ?? 'Unknown artist',
                    'plays' => (int) $row->plays,
                    'share' => $plays > 0 ? round((int) $row->plays / $plays, 4) : 0.0,
                ])
                ->all(),
        ];
    }

    /**
     * Rank the top artists of the last seven days and mark how each moved
     * compared with the seven days before.
     *
     * @return array<int, array{artist_id: string, name: string, plays: int, previous_plays: int, rank: int, change: int|null}>
     */
    private function rankMovement(User $user): array
    {
        $now = now();

        $current = $this->artistRankings($user, $now->copy()->subDays(7), $now);
        $previous = $this->artistRankings($user, $now->copy()->subDays(14), $now->copy()->subDays(7));

        $top = array_slice($current, 0, self::MOVEMENT_ARTISTS, preserve_keys: true);
        $names = $this->artistNames(array_keys($top));
        $movement = [];

        foreach ($top as $artistId => $row) {
            $previousRank = $previous[$artistId]['rank'] ?? null;

            $movement[] = [
                'artist_id' => (string) $artistId,
                'name' => $names[$artistId] ?? 'Unknown artist',
                'plays' => $row['plays'],
                'previous_plays' => $previous[$artistId]['plays'] ?? 0,
                'rank' => $row['rank'],
                'change' => $previousRank === null ? null : $previousRank - $row['rank'],
            ];
        }

        return $movement;
    }

    /**
     * @return array<string, array{plays: int, rank: int}>
     */
    private function artistRankings(User $user, Carbon $from, Carbon $to): array
    {
        $rows = $user->streams()
            ->where('played_at', '>=', $from)
            ->where('played_at', '<', $to)
            ->whereNotNull('artist_id')
            ->selectRaw('artist_id, COUNT(*) as plays')
            ->groupBy('artist_id')
            ->toBase()
            ->get()
            ->sortBy([['plays', 'desc'], ['artist_id', 'asc']])
            ->values();

        $rankings = [];

        foreach ($rows as $index => $row) {
            $rankings[(string) $row->artist_id] = [
                'plays' => (int) $row->plays,
                'rank' => $index + 1,
            ];
        }

        return $rankings;
    }

    /**
     * @param  array<int, string>  $artistIds
     * @return array<string, string>
     */
    private function artistNames(array $artistIds): array
    {
        if ($artistIds === []) {
            return [];
        }

        return Artist::query()
            ->whereIn('spotify_artist_id', array_values($artistIds))
            ->pluck('name', 'spotify_artist_id')
            ->all();
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
