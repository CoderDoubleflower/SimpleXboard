<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RetiredExtensionsTest extends TestCase
{
    public function test_extension_endpoints_stay_unreachable_without_extension_tables(): void
    {
        // This application has no migrations applied. Retired extension tables
        // must not be required to bootstrap either HTTP or console entry points.
        foreach (['v2_plugins', 'v2_notice', 'v2_knowledge', 'v2_mail_log', 'v2_mail_templates'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        $admin = new User();
        $admin->forceFill(['id' => 1, 'is_admin' => true, 'banned' => false]);
        Sanctum::actingAs($admin);
        $prefix = '/api/v2/' . hash('crc32b', config('app.key')) . '/';

        $endpoints = [
            ['GET', 'config/getEmailTemplate'], ['GET', 'config/getThemeTemplate'],
            ['POST', 'config/setTelegramWebhook'], ['POST', 'config/testSendMail'],
            ['GET', 'mail/template/list'], ['GET', 'mail/template/get'],
            ['POST', 'mail/template/save'], ['POST', 'mail/template/reset'], ['POST', 'mail/template/test'],
            ['GET', 'theme/getThemes'], ['POST', 'theme/upload'], ['POST', 'theme/delete'],
            ['POST', 'theme/saveThemeConfig'], ['POST', 'theme/getThemeConfig'],
            ['GET', 'plugin/types'], ['GET', 'plugin/getPlugins'], ['POST', 'plugin/upload'],
            ['POST', 'plugin/delete'], ['POST', 'plugin/install'], ['POST', 'plugin/uninstall'],
            ['POST', 'plugin/enable'], ['POST', 'plugin/disable'], ['GET', 'plugin/config'],
            ['POST', 'plugin/config'], ['POST', 'plugin/upgrade'],
            ['GET', 'notice/fetch'], ['POST', 'notice/save'], ['POST', 'notice/update'],
            ['POST', 'notice/drop'], ['POST', 'notice/show'], ['POST', 'notice/sort'],
            ['GET', 'knowledge/fetch'], ['GET', 'knowledge/getCategory'], ['POST', 'knowledge/save'],
            ['POST', 'knowledge/show'], ['POST', 'knowledge/drop'], ['POST', 'knowledge/sort'],
        ];
        foreach ($endpoints as [$method, $path]) {
            $response = $this->json($method, $prefix . $path);
            $this->assertSame(404, $response->status(), "$method $path must stay retired.");
        }
        foreach ([
            ['POST', '/api/v1/guest/telegram/webhook'],
            ['GET', '/api/v1/user/telegram/getBotInfo'],
            ['GET', '/api/v1/user/notice/fetch'],
            ['GET', '/api/v1/user/knowledge/fetch'],
            ['GET', '/api/v1/user/knowledge/getCategory'],
        ] as [$method, $path]) {
            $response = $this->json($method, $path);
            $this->assertSame(404, $response->status(), "$method $path must stay retired.");
        }
    }

    public function test_console_and_schedule_boot_without_plugins_or_mail(): void
    {
        $commands = Artisan::all();
        foreach (['backup:database', 'ws-server', 'xboard:install', 'xboard:update', 'reset:password'] as $name) {
            $this->assertArrayHasKey($name, $commands);
        }
        foreach (['hook:list', 'check:server', 'send:remindMail'] as $name) {
            $this->assertArrayNotHasKey($name, $commands);
        }

        $schedule = $this->app->make(Kernel::class)->resolveConsoleSchedule();
        $scheduledCommands = implode('\n', array_map(fn ($event) => $event->command ?? '', $schedule->events()));
        foreach (['xboard:statistics', 'check:traffic-exceeded', 'reset:log', 'cleanup:online-status', 'horizon:snapshot'] as $name) {
            $this->assertStringContainsString($name, $scheduledCommands);
        }
        foreach (['reset:traffic', 'hook:list', 'check:server', 'send:remindMail'] as $name) {
            $this->assertStringNotContainsString($name, $scheduledCommands);
        }
    }

    public function test_workers_keep_core_queues_and_never_consume_retired_notifications(): void
    {
        foreach (config('horizon.environments') as $environment => $supervisors) {
            $queues = [];
            foreach ($supervisors as $supervisor) {
                $queues = array_merge($queues, $supervisor['queue']);
            }
            sort($queues);
            $this->assertSame(['default', 'node_sync', 'stat', 'traffic_fetch', 'user_alive_sync'], $queues, $environment);
        }
    }
}
