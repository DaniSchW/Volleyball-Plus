<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

/**
 * Hosting without SSH: after a new package was uploaded via FTP, the cron
 * applies new database migrations on its next run.
 */
class MigratePending extends Command
{
    protected $signature = 'app:migrate-pending';

    protected $description = 'Run database migrations if the uploaded code contains new ones';

    public function handle(Migrator $migrator): int
    {
        if (! $migrator->repositoryExists()) {
            $this->warn('Not installed yet – run the web installer first.');

            return self::SUCCESS;
        }

        $files = $migrator->getMigrationFiles([database_path('migrations')]);
        $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());

        if ($pending === []) {
            return self::SUCCESS;
        }

        $this->info(count($pending).' pending migration(s).');

        return $this->call('migrate', ['--force' => true]);
    }
}
