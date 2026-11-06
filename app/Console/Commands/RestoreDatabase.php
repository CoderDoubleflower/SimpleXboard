<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class RestoreDatabase extends Command
{
    protected $signature = 'backup:restore {file} {--force : 确认在停止所有应用与队列进程后恢复数据库}';
    protected $description = '在维护模式下恢复原生 SQLite 备份，恢复前保留当前数据库副本';

    public function handle(BackupService $backups): int
    {
        if (!$this->option('force') || !app()->isDownForMaintenance()) {
            $this->error('先停止应用与队列进程并执行 php artisan down，再使用 --force 明确确认恢复。');
            return self::FAILURE;
        }
        try {
            $safety = $backups->restoreSqlite($this->argument('file'));
            $this->info('数据库已恢复；恢复前副本：' . $safety);
            $this->info('验证数据后清理配置/设置缓存，执行 php artisan up 并重启应用、队列与节点同步服务。');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('恢复失败：' . $exception->getMessage());
            return self::FAILURE;
        }
    }
}
