<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ResolvesTimezone
{
    /**
     * Use the visitor's browser timezone so local days and hours are correct,
     * falling back to the application timezone.
     */
    protected function resolveTimezone(Request $request): ?string
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
