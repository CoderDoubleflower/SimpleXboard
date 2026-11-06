<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\DbDumper\Databases\MySql;

/** Local database backups. Recovery runs offline through the CLI, never a web request. */
class BackupService
{
    public function directory(): string
    {
        return storage_path('backup');
    }

    public function listing(): array
    {
        return collect(glob($this->directory() . '/*.gz') ?: [])
            ->filter(fn ($path) => is_file($path) && !is_link($path))
            ->map(fn ($path) => $this->describe($path))
            ->sortByDesc('created_at')->values()->all();
    }

    public function path(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.(?:sql|sqlite)\.gz$/', $name)) {
            throw new RuntimeException('无效的备份文件名');
        }
        $path = $this->directory() . '/' . $name;
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('备份不存在');
        }
        return $path;
    }

    public function create(): array
    {
        File::ensureDirectoryExists($this->directory(), 0700);
        if (!chmod($this->directory(), 0700)) {
            throw new RuntimeException('无法设置备份目录权限');
        }
        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('当前备份入口支持 SQLite 和 MySQL；其他数据库请使用数据库原生备份工具。');
        }
        $suffix = $driver === 'sqlite' ? '.sqlite' : '.sql';
        $path = $this->directory() . '/' . now()->format('Y-m-d_H-i-s') . '_' . Str::lower(Str::random(8)) . $suffix;
        $compressed = $path . '.gz';
        try {
            $this->createPrivateFile($path);
            if ($driver === 'sqlite') {
                // VACUUM INTO creates a consistent native snapshot, including WAL changes.
                $pdo = DB::connection()->getPdo();
                $pdo->exec('VACUUM INTO ' . $pdo->quote($path));
            } else {
                $config = DB::connection()->getConfig();
                MySql::create()->setHost($config['host'])->setPort($config['port'] ?? 3306)
                    ->setDbName($config['database'])->setUserName($config['username'])
                    ->setPassword($config['password'] ?? '')->useSingleTransaction()->dumpToFile($path);
            }
            $this->createPrivateFile($compressed . '.tmp');
            $input = fopen($path, 'rb');
            $output = gzopen($compressed . '.tmp', 'wb6');
            if (!$input || !$output) {
                throw new RuntimeException('无法写入备份');
            }
            try {
                while (!feof($input)) {
                    $chunk = fread($input, 1024 * 1024);
                    if ($chunk === false || gzwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('压缩备份失败');
                    }
                }
            } finally {
                fclose($input);
                gzclose($output);
            }
            // A root CLI backup must remain readable by the application owner.
            $this->matchPermissions($compressed . '.tmp', $this->directory(), 0600);
            if (!rename($compressed . '.tmp', $compressed)) {
                throw new RuntimeException('保存备份失败');
            }
            return $this->describe($compressed);
        } finally {
            @unlink($path);
            @unlink($compressed . '.tmp');
        }
    }

    /** Caller must stop the application and workers. Returns the safety backup name. */
    public function restoreSqlite(string $name): string
    {
        $source = $this->path($name);
        if (DB::connection()->getDriverName() !== 'sqlite' || !str_ends_with($name, '.sqlite.gz')) {
            throw new RuntimeException('此命令仅恢复原生 SQLite 备份；SQL 备份请使用 mysql/sqlite3 原生导入。');
        }
        $target = DB::connection()->getDatabaseName();
        if ($target === ':memory:' || !is_file($target)) {
            throw new RuntimeException('恢复目标必须是当前配置的 SQLite 数据库文件');
        }
        $temp = $target . '.restore-' . Str::random(8);
        try {
            $this->createPrivateFile($temp);
            $input = gzopen($source, 'rb');
            $output = fopen($temp, 'wb');
            if (!$input || !$output) {
                throw new RuntimeException('无法打开备份');
            }
            try {
                while (!gzeof($input)) {
                    $chunk = gzread($input, 1024 * 1024);
                    if ($chunk === false || fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('读取备份失败');
                    }
                }
            } finally {
                gzclose($input);
                fclose($output);
            }
            $check = new \PDO('sqlite:' . $temp);
            if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('备份完整性校验未通过');
            }
            $check = null;
            $safety = $this->create();
            // Preserve access when the CLI and web workers run as different users.
            $this->matchPermissions($temp, $target);
            DB::disconnect();
            @unlink($target . '-wal');
            @unlink($target . '-shm');
            if (!rename($temp, $target)) {
                throw new RuntimeException('替换数据库失败');
            }
            DB::purge();
            return $safety['name'];
        } finally {
            @unlink($temp);
        }
    }

    private function createPrivateFile(string $path): void
    {
        $handle = fopen($path, 'xb');
        if (!$handle) {
            throw new RuntimeException('无法创建临时备份文件');
        }
        fclose($handle);
        if (!chmod($path, 0600)) {
            throw new RuntimeException('无法设置临时备份权限');
        }
    }

    private function matchPermissions(string $path, string $reference, ?int $mode = null): void
    {
        clearstatcache(true, $reference);
        $stat = stat($reference);
        if (!$stat
            || (fileowner($path) !== $stat['uid'] && !chown($path, $stat['uid']))
            || (filegroup($path) !== $stat['gid'] && !chgrp($path, $stat['gid']))
            || !chmod($path, $mode ?? ($stat['mode'] & 0777))) {
            throw new RuntimeException('无法保留数据库或备份的文件权限；当前数据未替换');
        }
    }

    private function describe(string $path): array
    {
        return ['name' => basename($path), 'size' => filesize($path), 'created_at' => filemtime($path)];
    }
}
