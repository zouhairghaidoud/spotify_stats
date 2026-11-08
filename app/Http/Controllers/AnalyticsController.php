<?php

namespace App\Http\Controllers;

use App\Services\ListeningAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    /**
     * Display listening analytics computed from the persisted streams.
     */
    public function __invoke(Request $request, ListeningAnalyticsService $analytics): Response
    {
        $user = $request->user();
        $timezone = $this->timezone($request);

        return Inertia::render('Analytics', [
            'spotifyConnected' => $user->isConnectedToSpotify(),
            ...$analytics->forUser($user, $timezone),
            'trends' => $analytics->artistTrends($user, $timezone),
            'comparison' => $analytics->artistComparison($user),
        ]);
    }

    /**
     * Use the visitor's browser timezone so the heatmap and streaks match
     * their local day, falling back to the application timezone.
     */
    private function timezone(Request $request): ?string
    {
        foreach ([$request->query('timezone'), $request->cookie('timezone')] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = urldecode($candidate);

            if (in_array($candidate, timezone_identifiers_list(), true)) {
                return $candidate;
            }
        }

        return null;
    }
}
