<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugin\PluginManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeaturePluginsTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;
    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/simplexboard-plugin-api-' . bin2hex(random_bytes(8));
        $this->files = new Filesystem();
        $this->files->makeDirectory($this->directory);
        $manager = new class($this->directory) extends PluginManager {
            public function __construct(string $directory)
            {
                $this->corePluginPath = $directory;
                $this->pluginPath = $directory . '/user';
            }
        };
        $this->app->instance(PluginManager::class, $manager);
        Sanctum::actingAs((new User())->forceFill(['id' => 1, 'is_admin' => true]));
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_catalog_only_exposes_feature_plugins_without_modifying_legacy_records(): void
    {
        $this->plugin('feature_fixture', 'feature', 'feature');
        $legacy = $this->plugin('legacy_payment', 'payment', 'payment');
        $legacy->update(['is_enabled' => true]);

        $this->getJson($this->path('types'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', 'feature');
        $this->getJson($this->path('getPlugins'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'feature_fixture')
            ->assertJsonPath('data.0.type', 'feature')
            ->assertJsonPath('data.0.is_installed', true)
            ->assertJsonPath('data.0.config.api_key.value', 'saved-secret');
        $this->getJson($this->path('getPlugins?type=payment'))->assertOk()
            ->assertExactJson(['data' => []]);
        $this->assertTrue($legacy->fresh()->is_enabled);
        $this->assertDatabaseCount('v2_plugins', 2);
    }

    public function test_retired_config_cannot_be_read_or_written_even_when_manifest_and_record_disagree(): void
    {
        foreach ([['payment', 'payment'], ['feature', 'payment'], ['payment', 'feature']] as $index => [$manifestType, $recordType]) {
            $code = 'retired_fixture_' . $index;
            $plugin = $this->plugin($code, $manifestType, $recordType);

            $this->getJson($this->path('config?code=' . $code))->assertStatus(400)
                ->assertJsonMissingPath('data');
            $this->postJson($this->path('config'), [
                'code' => $code, 'config' => ['api_key' => 'changed'],
            ])->assertStatus(400);
            $this->assertSame(['api_key' => 'saved-secret'], json_decode($plugin->fresh()->config, true));
        }
    }

    public function test_feature_config_remains_readable_and_writable(): void
    {
        $plugin = $this->plugin('feature_fixture', 'feature', 'feature');

        $this->getJson($this->path('config?code=feature_fixture'))->assertOk()
            ->assertJsonPath('data.api_key.value', 'saved-secret');
        $this->postJson($this->path('config'), [
            'code' => 'feature_fixture', 'config' => ['api_key' => 'updated'],
        ])->assertOk();
        $this->assertSame(['api_key' => 'updated'], json_decode($plugin->fresh()->config, true));
    }

    public function test_default_installer_skips_retired_manifests_and_installs_features(): void
    {
        $this->plugin('legacy_payment', 'payment', 'payment')->delete();
        $this->plugin('installable_feature', 'feature', 'feature')->delete();
        $this->files->put($this->directory . '/InstallableFeature/Plugin.php', <<<'PHP'
<?php
namespace Plugin\InstallableFeature;
class Plugin extends \App\Services\Plugin\AbstractPlugin {}
PHP);

        PluginManager::installDefaultPlugins();

        $this->assertDatabaseMissing('v2_plugins', ['code' => 'legacy_payment']);
        $this->assertDatabaseHas('v2_plugins', ['code' => 'installable_feature', 'is_enabled' => true]);
        $this->assertDatabaseCount('v2_plugins', 1);
    }

    private function plugin(string $code, string $manifestType, string $recordType): Plugin
    {
        $path = $this->directory . '/' . Str::studly($code);
        $this->files->makeDirectory($path);
        $this->files->put($path . '/config.json', json_encode([
            'name' => 'Plugin fixture',
            'code' => $code,
            'version' => '1.0.0',
            'description' => 'Plugin administration regression fixture',
            'author' => 'SimpleXboard',
            'type' => $manifestType,
            'config' => ['api_key' => ['type' => 'string', 'default' => 'default-value']],
        ]));

        return Plugin::create([
            'name' => 'Plugin fixture',
            'code' => $code,
            'version' => '1.0.0',
            'type' => $recordType,
            'is_enabled' => false,
            'config' => json_encode(['api_key' => 'saved-secret']),
        ]);
    }

    private function path(string $path): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/plugin/' . $path;
    }
}
