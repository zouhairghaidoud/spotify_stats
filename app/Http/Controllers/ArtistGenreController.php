<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ArtistGenreController extends Controller
{
    /**
     * Store a manually edited genre list for an artist the user listens to.
     */
    public function update(Request $request, string $artistId): RedirectResponse
    {
        $artist = Artist::query()
            ->where('spotify_artist_id', $artistId)
            ->first();

        if ($artist === null || ! $request->user()->streams()->where('artist_id', $artistId)->exists()) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validate([
            'genres' => ['array', 'max:8'],
            'genres.*' => ['string', 'max:40'],
        ]);

        $genres = collect($validated['genres'] ?? [])
            ->map(fn (mixed $genre): string => mb_strtolower(trim((string) $genre)))
            ->filter(fn (string $genre): bool => $genre !== '')
            ->unique()
            ->values()
            ->all();

        $artist->forceFill([
            'genres' => $genres,
            'genres_overridden' => true,
            'genres_synced_at' => now(),
        ])->save();

        return back()->with('success', 'Genres updated for '.$artist->name.'.');
    }
}
