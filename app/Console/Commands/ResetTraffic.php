<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ResetTraffic extends Command
{
    // The 2026_04_19 historical migration invokes --fix-null during a fresh install.
    protected $signature = 'reset:traffic {--fix-null} {--force}';
    protected $description = 'Historical migration compatibility; scheduled member resets are retired';

    public function handle(): int
    {
        $this->info('周期流量重置已退役；硬上限仅由管理员明确重置。');
        return self::SUCCESS;
    }
}
