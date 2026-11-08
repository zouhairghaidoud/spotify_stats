<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\RecapImageService;
use App\Services\RecapService;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class PublicRecapController extends Controller
{
    /**
     * Show a read-only recap for anyone holding the share token.
     */
    public function show(string $token, RecapService $recap): Response
    {
        $user = $this->userForToken($token);
        $data = $recap->forUser($user, $this->timezone($user));

        $topArtist = $data['top_artist']['name'] ?? null;
        $minutes = number_format($data['overview']['minutes']);

        return Inertia::render('Recap', [
            'shared' => true,
            'recap' => $data,
            'publicUrl' => route('recap.public', $token),
            'imageUrl' => route('recap.image', $token),
            'og' => [
                'title' => 'Listening recap · Spotify Stats',
                'description' => $topArtist === null
                    ? 'A personal Spotify listening recap.'
                    : $minutes.' minutes listened, '.$topArtist.' on repeat, and more.',
                'image' => route('recap.image', $token),
            ],
        ]);
    }

    /**
     * Render the Open Graph card for the shared recap.
     */
    public function image(string $token, RecapService $recap, RecapImageService $images): HttpResponse
    {
        $user = $this->userForToken($token);

        return response(
            $images->render($recap->forUser($user, $this->timezone($user))),
            200,
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
    }

    private function userForToken(string $token): User
    {
        return User::query()->where('recap_token', $token)->firstOrFail();
    }

    private function timezone(User $user): string
    {
        return filled($user->recap_timezone)
            ? (string) $user->recap_timezone
            : (string) config('app.timezone', 'UTC');
    }
}
