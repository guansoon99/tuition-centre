<?php

namespace App\Jobs;

use App\Models\AccessLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class LogMaterialAccessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public int $materialId,
        public string $action,
        public string $ipAddress,
        public ?string $userAgent,
        public Carbon $accessedAt,
    ) {}

    public function handle(): void
    {
        AccessLog::create([
            'user_id' => $this->userId,
            'material_id' => $this->materialId,
            'action' => $this->action,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'accessed_at' => $this->accessedAt,
        ]);
    }
}
