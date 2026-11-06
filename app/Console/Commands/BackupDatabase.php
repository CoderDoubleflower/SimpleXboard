<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database {upload?}';
    protected $description = '备份数据库，可选上传已配置的 Google Cloud Storage';

    public function handle(BackupService $backups): int
    {
        try {
            if ($this->argument('upload') && (!config('cloud_storage.google_cloud.key_file') || !config('cloud_storage.google_cloud.storage_bucket'))) {
                throw new \RuntimeException('未配置 Google Cloud Storage');
            }
            $file = $backups->create();
            if ($this->argument('upload')) {
                $storage = new StorageClient(['keyFilePath' => config('cloud_storage.google_cloud.key_file')]);
                $handle = fopen($backups->path($file['name']), 'rb');
                try {
                    $storage->bucket(config('cloud_storage.google_cloud.storage_bucket'))
                        ->upload($handle, ['name' => 'backup/' . $file['name']]);
                } finally {
                    fclose($handle);
                }
                $this->info('云端备份已上传，本地副本已保留。');
            }
            $this->info($backups->path($file['name']));
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('备份失败：' . $exception->getMessage());
            return self::FAILURE;
        }
    }
}
