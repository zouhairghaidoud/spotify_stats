<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTimezone;
use App\Services\ListeningDetailService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ListeningDetailController extends Controller
{
    use ResolvesTimezone;

    /**
     * Show every stored play for a single album.
     */
    public function album(Request $request, string $album, ListeningDetailService $details): Response
    {
        $artist = $request->query('artist');
        $artist = is_string($artist) && trim($artist) !== '' ? trim($artist) : null;

        $album = $details->album($request->user(), $album, $artist);

        if ($album === null) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('AlbumDetail', [
            'album' => $album,
            'timezone' => $this->resolveTimezone($request),
        ]);
    }

    /**
     * Show every stored play for a single artist.
     */
    public function artist(Request $request, string $artistId, ListeningDetailService $details): Response
    {
        $artist = $details->artist($request->user(), $artistId);

        if ($artist === null) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('ArtistDetail', [
            'artist' => $artist,
            'timezone' => $this->resolveTimezone($request),
        ]);
    }
}
