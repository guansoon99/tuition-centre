<?php

namespace App\Jobs;

use App\Services\StudentImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ImportStudentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public string $disk,
        public string $path,
    ) {}

    /**
     * @return array{ok: array, skipped: array, errors: array}
     */
    public function handle(StudentImporter $importer): array
    {
        $absolutePath = Storage::disk($this->disk)->path($this->path);
        $rows = $importer->parseFile(
            new \Illuminate\Http\UploadedFile($absolutePath, basename($absolutePath))
        );

        return $importer->processRows($rows, dryRun: false);
    }
}
