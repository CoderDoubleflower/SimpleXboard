<?php

namespace Tests\Feature\Server;

use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeMaintenanceService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NodeMaintenanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT_URL = 'https://github.com/CoderDoubleflower/SimpleXboard/releases/download/node-v1.13-simplexboard.2/node-manager.sh';
    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->temporary = sys_get_temp_dir() . '/simplexboard-maintenance-' . bin2hex(random_bytes(8));
        mkdir($this->temporary, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    public function test_command_and_credential_endpoints_require_an_administrator(): void
    {
        $machine = $this->machine();
        $paths = ['maintenanceCommands', 'installCommand', 'getToken'];
        foreach ($paths as $path) {
            $response = $this->getJson($this->base() . '/' . $path . '?id=' . $machine->id)->assertForbidden();
            $this->assertStringNotContainsString($machine->token, $response->getContent());
            $this->assertStringNotContainsString('node-manager.sh', $response->getContent());
        }
        Sanctum::actingAs($this->member());
        foreach ($paths as $path) $this->getJson($this->base() . '/' . $path . '?id=' . $machine->id)->assertForbidden();
        $this->postJson($this->base() . '/resetToken', ['id' => $machine->id])->assertForbidden();
        $this->assertSame($machine->token, $machine->fresh()->token);
    }

    public function test_readonly_commands_use_the_fixed_release_and_preserve_the_machine_record(): void
    {
        $this->admin();
        $machine = $this->machine();
        admin_setting(['app_url' => 'https://panel.example.test/base/']);
        $before = (array) DB::table('v2_server_machine')->where('id', $machine->id)->first();
        $response = $this->getJson($this->base() . '/maintenanceCommands?id=' . $machine->id
            . '&version=latest&repository=other%2Fproject&script_url=https%3A%2F%2Fother.example%2Finstaller.sh')->assertOk();
        $response->assertJsonPath('data.version', 'v1.13-simplexboard.2')
            ->assertJsonPath('data.platforms', ['linux-amd64', 'linux-arm64']);
        $this->assertNoStore($response);
        $commands = $response->json('data');
        foreach (['install_command', 'upgrade_command', 'uninstall_command', 'purge_command'] as $key) {
            $this->assertStringContainsString(self::SCRIPT_URL, $commands[$key]);
            $this->assertStringNotContainsString('raw.githubusercontent.com', $commands[$key]);
            $this->assertStringNotContainsString('latest', $commands[$key]);
            $this->assertStringNotContainsString('other.example', $commands[$key]);
            $this->assertDoesNotMatchRegularExpression('/[\r\n\x00]/', $commands[$key]);
            $this->assertStringNotContainsString('|', $commands[$key]);
            $this->assertStringNotContainsString('sudo', $commands[$key]);
        }
        foreach (['upgrade_command', 'uninstall_command', 'purge_command'] as $key) {
            $this->assertStringNotContainsString($machine->token, $commands[$key]);
            $this->assertStringNotContainsString('panel.example.test', $commands[$key]);
            $this->assertStringNotContainsString('--token', $commands[$key]);
            $this->assertStringNotContainsString('--machine-id', $commands[$key]);
        }
        $old = $this->getJson($this->base() . '/installCommand?id=' . $machine->id)->assertOk();
        $this->assertSame($commands['install_command'], $old->json('data.command'));
        $this->assertNoStore($old);
        $token = $this->getJson($this->base() . '/getToken?id=' . $machine->id)->assertOk()
            ->assertJsonPath('data.token', $machine->token);
        $this->assertNoStore($token);
        $this->assertSame($before, (array) DB::table('v2_server_machine')->where('id', $machine->id)->first());
        $this->assertDatabaseCount('v2_admin_audit_log', 0);
    }

    public function test_new_machine_response_keeps_install_command_and_token_compatibility(): void
    {
        $this->admin();
        admin_setting(['app_url' => 'https://panel.example.test']);
        $created = $this->postJson($this->base() . '/save', ['name' => 'New VPS'])->assertOk();
        $this->assertNoStore($created);
        $machine = ServerMachine::findOrFail($created->json('data.id'));
        $this->assertSame($machine->token, $created->json('data.token'));
        $commands = $this->getJson($this->base() . '/maintenanceCommands?id=' . $machine->id)->assertOk()->json('data');
        $this->assertSame($created->json('data.install_command'), $commands['install_command']);
        $this->assertStringContainsString(self::SCRIPT_URL, $commands['install_command']);
        $this->assertStringContainsString("'install' '--panel' 'https://panel.example.test' '--token'", $commands['install_command']);
        $audit = DB::table('v2_admin_audit_log')->where('action', 'server_machine.save')->first();
        $this->assertStringNotContainsString($machine->token, $audit->request_data);
    }

    public function test_shell_roundtrip_preserves_special_characters_and_only_runs_the_downloaded_script(): void
    {
        $machine = $this->machine();
        $marker = $this->temporary . '/injected';
        $panel = "https://panel.example.test/'; touch " . $marker . '; echo `touch ' . $marker . '` $(touch ' . $marker . ') "\\$HOME';
        $machine->token = "token'; touch " . $marker . '; echo $(touch ' . $marker . ') `touch ' . $marker . '` "\\$HOME';
        $commands = app(NodeMaintenanceService::class)->commands($machine, $panel);
        $expected = [
            'install_command' => ['install', '--panel', $panel, '--token', $machine->token, '--machine-id', (string) $machine->id],
            'upgrade_command' => ['upgrade'],
            'uninstall_command' => ['uninstall'],
            'purge_command' => ['uninstall', '--purge'],
        ];
        foreach ($expected as $key => $arguments) {
            $this->assertDoesNotMatchRegularExpression('/[\r\n\x00]/', $commands[$key]);
            [$process, $actual, $curlArguments] = $this->executeFixture($commands[$key]);
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame($arguments, $actual);
            $this->assertSame(['-fsSL', '--retry', '3', self::SCRIPT_URL], array_slice($curlArguments, 0, 4));
            $this->assertFileDoesNotExist($marker);
        }
    }

    public function test_partial_download_is_never_executed_and_temporary_file_is_removed(): void
    {
        $commands = app(NodeMaintenanceService::class)->commands($this->machine(), 'https://panel.example.test');
        [$process, $arguments, $curlArguments] = $this->executeFixture($commands['upgrade_command'], failDownload: true);
        $this->assertSame(22, $process->getExitCode());
        $this->assertSame([], $arguments);
        $this->assertSame(['-fsSL', '--retry', '3', self::SCRIPT_URL], array_slice($curlArguments, 0, 4));
    }

    public function test_newlines_are_rejected_and_failed_new_machine_creation_rolls_back(): void
    {
        $this->admin();
        $machine = $this->machine();
        admin_setting(['app_url' => "https://panel.example.test\nmalformed"]);
        $this->getJson($this->base() . '/maintenanceCommands?id=' . $machine->id)->assertUnprocessable();
        $this->postJson($this->base() . '/save', ['name' => 'Must not persist'])->assertUnprocessable();
        $this->assertDatabaseMissing('v2_server_machine', ['name' => 'Must not persist']);
        admin_setting(['app_url' => 'https://panel.example.test']);
        $machine->update(['token' => "token\r\ninvalid"]);
        $this->getJson($this->base() . '/maintenanceCommands?id=' . $machine->id)->assertUnprocessable();
    }

    public function test_invalid_machine_id_is_rejected_and_token_rotation_response_is_not_cached(): void
    {
        $this->admin();
        $this->getJson($this->base() . '/maintenanceCommands')->assertUnprocessable();
        $this->getJson($this->base() . '/maintenanceCommands?id=999999')->assertUnprocessable();
        $machine = $this->machine();
        $rotated = $this->postJson($this->base() . '/resetToken', ['id' => $machine->id])->assertOk();
        $this->assertNoStore($rotated);
        $this->assertNotSame($machine->token, $rotated->json('data.token'));
        $this->assertSame($machine->fresh()->token, $rotated->json('data.token'));
    }

    /** Executes the real shell wrapper with a local curl fixture; never contacts the network. */
    private function executeFixture(string $command, bool $failDownload = false): array
    {
        $directory = $this->temporary . '/' . bin2hex(random_bytes(5));
        mkdir($directory . '/bin', 0700, true);
        mkdir($directory . '/downloads', 0700, true);
        file_put_contents($directory . '/payload.sh', <<<'SH'
#!/usr/bin/env bash
set -eu
printf '%s\0' "$@" > "$MAINTENANCE_ARGUMENTS"
SH);
        file_put_contents($directory . '/bin/curl', <<<'SH'
#!/usr/bin/env bash
set -eu
printf '%s\0' "$@" > "$MAINTENANCE_CURL_ARGUMENTS"
while [ "$#" -gt 0 ]; do
    if [ "$1" = '-o' ]; then destination="$2"; break; fi
    shift
done
cp "$MAINTENANCE_PAYLOAD" "$destination"
if [ "$MAINTENANCE_FAIL_DOWNLOAD" = '1' ]; then exit 22; fi
SH);
        chmod($directory . '/bin/curl', 0700);
        $process = new Process(['bash', '-c', $command], null, [
            'PATH' => $directory . '/bin:' . getenv('PATH'),
            'TMPDIR' => $directory . '/downloads',
            'MAINTENANCE_PAYLOAD' => $directory . '/payload.sh',
            'MAINTENANCE_ARGUMENTS' => $directory . '/arguments',
            'MAINTENANCE_CURL_ARGUMENTS' => $directory . '/curl-arguments',
            'MAINTENANCE_FAIL_DOWNLOAD' => $failDownload ? '1' : '0',
        ]);
        $process->setTimeout(10);
        $process->run();
        $decode = fn ($path) => is_file($path) ? explode("\0", rtrim(file_get_contents($path), "\0")) : [];
        $this->assertSame([], File::files($directory . '/downloads'), 'Downloaded installer was not cleaned up.');
        return [$process, $decode($directory . '/arguments'), $decode($directory . '/curl-arguments')];
    }

    private function assertNoStore($response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    private function base(): string
    {
        // Use an absolute request URL so app_url's public prefix does not change test routing.
        return 'http://localhost/api/v2/' . admin_setting('secure_path', hash('crc32b', config('app.key'))) . '/server/machine';
    }

    private function machine(): ServerMachine
    {
        return ServerMachine::create(['name' => 'Existing VPS', 'token' => 'existing-machine-private-token', 'is_active' => true]);
    }

    private function member(): User
    {
        $user = app(UserService::class)->createUser(['name' => 'Maintenance test']);
        $user->save();
        return $user;
    }

    private function admin(): void
    {
        $admin = $this->member();
        $admin->update(['is_admin' => true]);
        Sanctum::actingAs($admin);
    }
}
