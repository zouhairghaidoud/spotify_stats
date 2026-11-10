<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTimezone;
use App\Services\RecapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RecapController extends Controller
{
    use ResolvesTimezone;

    /**
     * Show the owner's recap with sharing controls.
     */
    public function show(Request $request, RecapService $recap): Response
    {
        $user = $request->user();
        $token = $user->recap_token;
        $timezone = $this->resolveTimezone($request);
        $years = $recap->availableYears($user);
        $year = $request->query('year');
        $year = is_numeric($year) && in_array((int) $year, $years, true) ? (int) $year : null;

        return Inertia::render('Recap', [
            'shared' => false,
            'recap' => $recap->forUser($user, $timezone, $year),
            'year' => $year,
            'years' => $years,
            'publicUrl' => filled($token) ? route('recap.public', $token) : null,
            'imageUrl' => filled($token) ? route('recap.image', $token) : null,
        ]);
    }

    /**
     * Create (or keep) the public recap link, capturing the timezone the
     * owner is viewing the recap in so the public version matches.
     */
    public function share(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'recap_token' => filled($user->recap_token) ? $user->recap_token : Str::random(48),
            'recap_timezone' => $this->resolveTimezone($request) ?? config('app.timezone'),
        ])->save();

        return back()->with('success', 'Your recap link is ready to share.');
    }

    /**
     * Revoke the public link and forget the stored recap timezone.
     */
    public function revoke(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'recap_token' => null,
            'recap_timezone' => null,
        ])->save();

        return back()->with('success', 'Your public recap link was revoked.');
    }
}
