<?php

namespace CorelixIo\Platform\Jobs;

use CorelixIo\Platform\Models\CustomTemplateSource;
use CorelixIo\Platform\Services\TemplateSourceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncTemplateSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public CustomTemplateSource $source) {}

    public function uniqueId(): string
    {
        return 'sync-template-'.$this->source->uuid;
    }

    public function handle(): void
    {
        if (! config('corelix-platform.enabled', false)) {
            return;
        }

        try {
            TemplateSourceService::syncSource($this->source);
        } catch (\Throwable $e) {
            Log::error('SyncTemplateSourceJob: Failed to sync source '.$this->source->name, [
                'source_uuid' => $this->source->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
