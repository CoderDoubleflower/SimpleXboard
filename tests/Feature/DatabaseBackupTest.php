<?php

namespace Tests\Feature;

use App\Services\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    private string $temporary;
    private string $oldStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir() . '/simplexboard-backup-' . bin2hex(random_bytes(8));
        File::makeDirectory($this->temporary, 0700, true);
        $this->oldStorage = app()->storagePath();
        app()->useStoragePath($this->temporary);
        touch($this->temporary . '/database.sqlite');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->temporary . '/database.sqlite']);
        DB::purge('sqlite');
        DB::statement('CREATE TABLE members (id INTEGER PRIMARY KEY, token TEXT NOT NULL, uuid TEXT NOT NULL)');
        DB::table('members')->insert(['id' => 9, 'token' => 'preserved-subscription-token', 'uuid' => 'preserved-node-uuid']);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        app()->useStoragePath($this->oldStorage);
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    public function test_consistent_backup_and_offline_restore_preserve_identity_and_create_safety_copy(): void
    {
        $service = app(BackupService::class);
        $database = $this->temporary . '/database.sqlite';
        chmod($database, 0660);
        $owner = fileowner($database);
        $group = filegroup($database);
        File::ensureDirectoryExists($service->directory(), 0775);
        chmod($service->directory(), 0775);
        $backup = $service->create();
        clearstatcache();
        $this->assertSame(0700, fileperms($service->directory()) & 0777);
        $this->assertSame(0600, fileperms($service->path($backup['name'])) & 0777);
        $this->assertGreaterThan(0, $backup['size']);
        $this->assertStringEndsWith('.sqlite.gz', $backup['name']);
        DB::table('members')->where('id', 9)->update(['token' => 'later-change']);
        DB::table('members')->insert(['id' => 10, 'token' => 'later-member', 'uuid' => 'later-uuid']);
        $safety = $service->restoreSqlite($backup['name']);
        clearstatcache();
        $this->assertSame(0660, fileperms($database) & 0777);
        $this->assertSame($owner, fileowner($database));
        $this->assertSame($group, filegroup($database));
        $this->assertNotSame($backup['name'], $safety);
        $this->assertSame('preserved-subscription-token', DB::table('members')->where('id', 9)->value('token'));
        $this->assertSame('preserved-node-uuid', DB::table('members')->where('id', 9)->value('uuid'));
        $this->assertSame(1, DB::table('members')->count());
        $this->assertCount(2, $service->listing());
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
    }

    public function test_restore_command_refuses_online_or_unconfirmed_restore(): void
    {
        $file = app(BackupService::class)->create();
        $this->artisan('backup:restore', ['file' => $file['name'], '--force' => true])->assertExitCode(1);
        $this->artisan('backup:restore', ['file' => $file['name']])->assertExitCode(1);
        $this->assertSame('preserved-subscription-token', DB::table('members')->value('token'));
    }

    public function test_backup_path_rejects_traversal_and_symlinks(): void
    {
        $service = app(BackupService::class);
        File::ensureDirectoryExists($service->directory(), 0700);
        symlink($this->temporary . '/database.sqlite', $service->directory() . '/linked.sqlite.gz');
        foreach (['../database.sqlite', 'linked.sqlite.gz', 'missing.sqlite.gz'] as $name) {
            try {
                $service->path($name);
                $this->fail('Unsafe backup path accepted.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_corrupt_backup_does_not_overwrite_current_database(): void
    {
        $service = app(BackupService::class);
        File::ensureDirectoryExists($service->directory(), 0700);
        file_put_contents($service->directory() . '/broken.sqlite.gz', gzencode('this is not a database'));
        try {
            $service->restoreSqlite('broken.sqlite.gz');
            $this->fail('Corrupt backup accepted.');
        } catch (\Throwable $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame('preserved-subscription-token', DB::table('members')->value('token'));
    }
}
