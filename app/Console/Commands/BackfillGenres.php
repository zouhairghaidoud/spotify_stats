<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GenreBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('genres:backfill {--user= : Only backfill the given user id} {--limit=50 : Artists to check per user} {--force : Recheck artists that already have genres}')]
#[Description('Backfill missing artist genres from MusicBrainz')]
class BackfillGenres extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GenreBackfillService $genres): int
    {
        $query = User::query();

        if ($userId = $this->option('user')) {
            $query->whereKey($userId);
        }

        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');
        $failed = 0;

        foreach ($query->lazyById() as $user) {
            $result = $genres->backfill($user, $limit, $force);

            $failed += $result['failed'];

            $this->line(
                "{$user->email}: checked {$result['checked']}, updated {$result['updated']}, failed {$result['failed']}.",
            );
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
