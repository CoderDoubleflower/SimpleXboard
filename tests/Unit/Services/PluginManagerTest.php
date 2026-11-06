<?php

namespace Tests\Unit\Services;

use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PluginManagerTest extends TestCase
{
    private string $directory;
    private Filesystem $files;
    private ?Container $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/simplexboard-plugins-' . bin2hex(random_bytes(8));
        $this->files = new Filesystem();
        $this->files->makeDirectory($this->directory);

        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('files', $this->files);
        $container->instance('log', new NullLogger());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->directory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);

        parent::tearDown();
    }

    public function test_legacy_payment_plugin_is_rejected_before_its_php_is_loaded(): void
    {
        $this->writePlugin('LegacyPayment', 'legacy_payment', 'payment', <<<'PHP'
<?php
namespace Plugin\LegacyPayment;
class Plugin implements \App\Contracts\PaymentInterface {}
PHP);

        $manager = $this->manager();

        self::assertNull($manager->loadForTest('legacy_payment'));
        self::assertFalse(class_exists('Plugin\\LegacyPayment\\Plugin', false));
        self::assertFalse($manager->validateForTest($this->manifest('legacy_payment', 'payment')));
    }

    public function test_feature_plugins_remain_loadable(): void
    {
        $this->writePlugin('FeatureFixture', 'feature_fixture', 'feature', <<<'PHP'
<?php
namespace Plugin\FeatureFixture;
class Plugin extends \App\Services\Plugin\AbstractPlugin {}
PHP);

        $manager = $this->manager();
        $plugin = $manager->loadForTest('feature_fixture');

        self::assertInstanceOf(AbstractPlugin::class, $plugin);
        self::assertSame('feature_fixture', $plugin->getPluginCode());
        self::assertSame($plugin, $manager->loadForTest('feature_fixture'));
        self::assertTrue($manager->validateForTest($this->manifest('feature_fixture', 'feature')));
    }

    private function manifest(string $code, string $type): array
    {
        return [
            'name' => 'Plugin fixture',
            'code' => $code,
            'type' => $type,
            'version' => '1.0.0',
            'description' => 'Plugin compatibility regression fixture',
            'author' => 'SimpleXboard',
        ];
    }

    private function writePlugin(string $directory, string $code, string $type, string $php): void
    {
        $path = $this->directory . '/' . $directory;
        $this->files->makeDirectory($path);
        $this->files->put($path . '/config.json', json_encode($this->manifest($code, $type)));
        $this->files->put($path . '/Plugin.php', $php);
    }

    private function manager(): PluginManager
    {
        return new class($this->directory) extends PluginManager {
            public function __construct(string $directory)
            {
                $this->corePluginPath = $directory;
                $this->pluginPath = $directory . '/user';
            }

            public function loadForTest(string $code): ?AbstractPlugin
            {
                return $this->loadPlugin($code);
            }

            public function validateForTest(array $config): bool
            {
                return $this->validateConfig($config);
            }
        };
    }
}
