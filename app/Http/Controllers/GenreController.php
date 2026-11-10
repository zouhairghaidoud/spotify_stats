<?php

namespace App\Http\Controllers;

use App\Jobs\BackfillGenresJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GenreController extends Controller
{
    /**
     * Queue a MusicBrainz genre lookup for the user's most played artists.
     */
    public function store(Request $request): RedirectResponse
    {
        BackfillGenresJob::dispatch($request->user(), 60);

        return back()->with(
            'success',
            'Genre lookup queued. Your top genres will appear once the background job finishes.',
        );
    }
}
