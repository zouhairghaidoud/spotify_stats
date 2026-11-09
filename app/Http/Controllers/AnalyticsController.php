<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTimezone;
use App\Services\HistoryImportService;
use App\Services\ListeningAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    use ResolvesTimezone;

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

        return Inertia::render('Analytics', [
            'spotifyConnected' => $user->isConnectedToSpotify(),
            ...$analytics->forUser($user, $timezone, $search),
            'trends' => $analytics->artistTrends($user, $timezone),
            'comparison' => $analytics->artistComparison($user),
            'search' => $search ?? '',
            'importSummary' => $imports->summary($user),
        ]);
    }
}
