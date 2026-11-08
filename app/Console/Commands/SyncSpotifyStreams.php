<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StreamSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('spotify:sync {--user= : Only sync the given user id}')]
#[Description('Persist recently played Spotify tracks for connected users')]
class SyncSpotifyStreams extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(StreamSyncService $streams): int
    {
        $query = User::query()->whereNotNull('spotify_refresh_token');

        if ($userId = $this->option('user')) {
            $query->whereKey($userId);
        }

        $synced = 0;
        $failed = 0;

        foreach ($query->lazyById() as $user) {
            try {
                $stored = $streams->sync($user);

                $synced++;
                $this->line("Stored {$stored} new plays for {$user->email}.");
            } catch (Throwable $exception) {
                report($exception);

                $failed++;
                $this->warn("Could not sync {$user->email}: {$exception->getMessage()}");
            }
        }

        $this->info("Synced {$synced} account(s), {$failed} failure(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
