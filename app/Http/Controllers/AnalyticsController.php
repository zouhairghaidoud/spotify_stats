<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTimezone;
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
    public function __invoke(Request $request, ListeningAnalyticsService $analytics): Response
    {
        $user = $request->user();
        $timezone = $this->resolveTimezone($request);

        return Inertia::render('Analytics', [
            'spotifyConnected' => $user->isConnectedToSpotify(),
            ...$analytics->forUser($user, $timezone),
            'trends' => $analytics->artistTrends($user, $timezone),
            'comparison' => $analytics->artistComparison($user),
        ]);
    }
}
