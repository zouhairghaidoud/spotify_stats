<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SpotifyService;
use App\Services\StreamSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SpotifyController extends Controller
{
    private const INTENT_CONNECT = 'connect';

    private const INTENT_LOGIN = 'login';

    /**
     * Redirect the user to Spotify's authorization page.
     *
     * Guests are signing in (or registering) with Spotify; authenticated
     * users are connecting Spotify to their existing account.
     */
    public function redirect(Request $request, SpotifyService $spotify): RedirectResponse
    {
        $intent = $request->user() ? self::INTENT_CONNECT : self::INTENT_LOGIN;
        $state = Str::random(40);

        $request->session()->put('spotify_oauth_state', $state);
        $request->session()->put('spotify_oauth_intent', $intent);

        return redirect()->away($spotify->authorizationUrl($state));
    }

    /**
     * Handle Spotify's callback.
     *
     * For guest logins a local account is found (or created) and logged in.
     * For authenticated users the tokens are linked to their account.
     */
    public function callback(Request $request, SpotifyService $spotify, StreamSyncService $streams): RedirectResponse
    {
        $expectedState = $request->session()->pull('spotify_oauth_state');
        $intent = $request->session()->pull('spotify_oauth_intent', self::INTENT_CONNECT);

        if (! is_string($expectedState) || ! hash_equals($expectedState, (string) $request->query('state'))) {
            abort(403, 'Invalid Spotify OAuth state.');
        }

        if ($request->query('error') !== null) {
            return $this->fail($intent, 'Spotify authorization was cancelled.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->fail($intent, 'Spotify did not return an authorization code.');
        }

        try {
            $tokens = $spotify->exchangeCode($code);
            $profile = $spotify->getProfile((string) $tokens['access_token']);

            if ($intent === self::INTENT_LOGIN) {
                $user = $this->findOrCreateUser($profile);

                $this->linkSpotifyAccount($user, $tokens, $profile);
                $this->syncStreams($streams, $user);

                Auth::login($user, remember: true);
                $request->session()->regenerate();

                return to_route('dashboard')->with('success', 'Signed in with Spotify.');
            }

            $user = $request->user();

            if ($user === null) {
                return to_route('login')->with('error', 'Please sign in before connecting Spotify.');
            }

            $this->linkSpotifyAccount($user, $tokens, $profile);
            $this->syncStreams($streams, $user);
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail($intent, 'Could not connect to Spotify. Please try again.');
        }

        return to_route('dashboard')->with('success', 'Spotify account connected.');
    }

    /**
     * Find the local user matching the Spotify profile, creating one if needed.
     *
     * @param  array<string, mixed>  $profile
     */
    private function findOrCreateUser(array $profile): User
    {
        $spotifyId = $profile['id'] ?? null;

        if (blank($spotifyId)) {
            throw new RuntimeException('The Spotify profile did not include an id.');
        }

        $user = User::query()->where('spotify_id', $spotifyId)->first();

        if ($user !== null) {
            return $user;
        }

        $email = $profile['email'] ?? null;

        if (filled($email)) {
            $user = User::query()->where('email', $email)->first();

            if ($user !== null) {
                return $user;
            }
        }

        $displayName = $profile['display_name'] ?? null;

        return User::create([
            'name' => filled($displayName)
                ? $displayName
                : (filled($email) ? Str::before($email, '@') : 'Spotify User'),
            'email' => filled($email) ? $email : 'spotify-'.$spotifyId.'@users.noreply.spotify.com',
            'password' => Str::password(32),
        ]);
    }

    /**
     * Store the latest Spotify credentials on the user.
     *
     * @param  array<string, mixed>  $tokens
     * @param  array<string, mixed>  $profile
     */
    private function linkSpotifyAccount(User $user, array $tokens, array $profile): void
    {
        $user->forceFill([
            'spotify_id' => $profile['id'] ?? $user->spotify_id,
            'spotify_avatar' => $profile['images'][0]['url'] ?? $user->spotify_avatar,
            'spotify_access_token' => $tokens['access_token'],
            'spotify_refresh_token' => $tokens['refresh_token'] ?? $user->spotify_refresh_token,
            'spotify_token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'spotify_scopes' => isset($tokens['scope'])
                ? (string) $tokens['scope']
                : $user->spotify_scopes,
        ])->save();
    }

    /**
     * Seed the listening history right after the account is linked.
     *
     * A failure here must not block the login or connection flow.
     */
    private function syncStreams(StreamSyncService $streams, User $user): void
    {
        try {
            $streams->sync($user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Send the user back to the most relevant screen with an error message.
     */
    private function fail(string $intent, string $message): RedirectResponse
    {
        return $intent === self::INTENT_LOGIN
            ? to_route('login')->with('error', $message)
            : to_route('dashboard')->with('error', $message);
    }
}
