<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTimezone;
use App\Services\HistoryImportService;
use App\Services\ListeningAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    use ResolvesTimezone;

    /**
     * Supported time ranges for stored-history analytics.
     *
     * @var array<int, string>
     */
    private const RANGES = ['7d', '30d', '90d', '1y', 'all'];

    /**
     * Display listening analytics computed from the persisted streams.
     */
    public function __invoke(
        Request $request,
        ListeningAnalyticsService $analytics,
        HistoryImportService $imports,
    ): Response {
        $user = $request->user();
        $timezone = $this->resolveTimezone($request);
        $search = $request->query('search');
        $search = is_string($search) && trim($search) !== '' ? trim($search) : null;

        $range = $request->query('range');
        $range = is_string($range) && in_array($range, self::RANGES, true) ? $range : 'all';
        $from = $this->rangeStart($range);

        return Inertia::render('Analytics', [
            'spotifyConnected' => $user->isConnectedToSpotify(),
            ...$analytics->forUser($user, $timezone, $search, $from),
            'trends' => $analytics->artistTrends($user, $timezone),
            'comparison' => $analytics->artistComparison($user),
            'search' => $search ?? '',
            'range' => $range,
            'importSummary' => $imports->summary($user),
            'imports' => $imports->recent($user),
        ]);
    }

    private function rangeStart(string $range): ?Carbon
    {
        return match ($range) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => null,
        };
    }
}
