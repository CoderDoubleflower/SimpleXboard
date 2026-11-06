<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class XboardUpdate extends Command
{
    protected $signature = 'xboard:update';

    protected $description = 'Apply database migrations and clear caches for the installed SimpleXboard code';

    public function handle(): int
    {
        // Updating source files or images is an operator action. This command
        // only applies migrations shipped with the current local release.
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
            if ($this->call($command) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->info('本地版本迁移完成。请重启 Octane、Horizon 和节点 WebSocket 服务以加载新代码。');

        return self::SUCCESS;
    }
}
