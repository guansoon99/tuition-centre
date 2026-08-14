<?php

namespace App\Console\Commands;

use App\Support\PrivateFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Nightly backup, written off this machine.
 *
 * The point of the "off this machine" part: a backup sitting in /var/backups
 * shares the fate of the thing it protects. It covers the common failure —
 * someone deletes a course, a migration goes wrong — and none of the total
 * ones: disk failure, the droplet being destroyed, a compromise that wipes
 * local files too. Those are the cases backups exist for.
 *
 * So the dump goes to R2, which is already configured, already private, and
 * costs fractions of a cent for a fortnight.
 *
 * Only the database is dumped. Every uploaded file — submissions, material
 * PDFs, lesson media, and now branding too — already lives in R2, so copying
 * them into R2 again would buy nothing. The database is the one thing that
 * exists solely on this machine.
 */
class BackupToR2 extends Command
{
    protected $signature = 'backup:run
                            {--keep=14 : Delete backups older than this many days}
                            {--dry-run : Report what would happen, upload nothing}';

    protected $description = 'Back up the database to R2';

    private const PREFIX = 'backups';

    public function handle(): int
    {
        if (! PrivateFile::canPresign()) {
            $this->error('The storage disk is not R2/S3 — there is nowhere off-machine to put this.');
            $this->line('Set FILESYSTEM_DISK=r2. Refusing rather than writing a backup to the same disk.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $stamp = now()->format('Y-m-d_His');
        $temp = storage_path('app/backup-tmp');

        if (! is_dir($temp) && ! mkdir($temp, 0700, true) && ! is_dir($temp)) {
            $this->error("Could not create {$temp}");

            return self::FAILURE;
        }

        $artefacts = [];

        try {
            $artefacts[] = $this->dumpDatabase($temp, $stamp);

            foreach ($artefacts as $file) {
                $key = self::PREFIX.'/'.basename($file);
                $size = number_format(filesize($file) / 1024, 1).' KB';

                if ($dryRun) {
                    $this->line("would upload  {$key}  ({$size})");

                    continue;
                }

                // Streamed, so a large dump is not held in memory.
                $handle = fopen($file, 'rb');
                try {
                    Storage::disk(PrivateFile::disk())->put($key, $handle);
                } finally {
                    fclose($handle);
                }

                $this->line("uploaded  {$key}  ({$size})");
            }
        } finally {
            // The local copies are working files, not the backup.
            foreach ($artefacts as $file) {
                @unlink($file);
            }
        }

        $this->prune((int) $this->option('keep'), $dryRun);

        $this->info($dryRun ? 'Dry run complete.' : 'Backup complete.');

        return self::SUCCESS;
    }

    /**
     * Dump the database, whichever driver is in use.
     *
     * SQLite is the dev database and is a single file, so it is simply copied.
     * MySQL needs mysqldump; --single-transaction matters, because without it
     * the dump locks tables and the site stalls behind the nightly backup.
     */
    private function dumpDatabase(string $temp, string $stamp): string
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if ($config['driver'] === 'sqlite') {
            $out = "{$temp}/db-{$stamp}.sqlite";
            copy($config['database'], $out);
            $this->line('dumped    sqlite database file');

            return $out;
        }

        $out = "{$temp}/db-{$stamp}.sql";

        $process = new Process([
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--password='.$config['password'],
            $config['database'],
        ]);
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use ($out) {
            if ($type === Process::OUT) {
                file_put_contents($out, $buffer, FILE_APPEND);
            }
        });

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }

        $this->line('dumped    mysql database');

        return $out;
    }

    /**
     * Delete backups older than the retention window.
     *
     * Without this they accumulate forever — the whole point of a nightly job
     * is that it runs unattended, so it has to clean up after itself.
     */
    private function prune(int $keepDays, bool $dryRun): void
    {
        $disk = Storage::disk(PrivateFile::disk());
        $cutoff = now()->subDays($keepDays)->getTimestamp();
        $removed = 0;

        foreach ($disk->files(self::PREFIX) as $path) {
            if ($disk->lastModified($path) >= $cutoff) {
                continue;
            }

            if ($dryRun) {
                $this->line("would delete  {$path}");
            } else {
                $disk->delete($path);
                $this->line("deleted   {$path}");
            }
            $removed++;
        }

        $this->line("retention {$keepDays} days — {$removed} old backup(s) ".($dryRun ? 'would be removed' : 'removed'));
    }
}
