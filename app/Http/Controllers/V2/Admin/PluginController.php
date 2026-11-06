<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class PluginController extends Controller
{
    protected PluginManager $pluginManager;
    protected PluginConfigService $configService;

    public function __construct(
        PluginManager $pluginManager,
        PluginConfigService $configService
    ) {
        $this->pluginManager = $pluginManager;
        $this->configService = $configService;
    }

    /**
     * 获取所有插件类型
     */
    public function types()
    {
        return response()->json([
            'data' => [
                [
                    'value' => Plugin::TYPE_FEATURE,
                    'label' => '功能',
                    'description' => '提供功能扩展的插件，如Telegram登录、邮件通知等',
                    'icon' => '🔧'
                ]
            ]
        ]);
    }

    /**
     * 获取插件列表
     */
    public function index(Request $request)
    {
        $type = $request->query('type');
        if ($type && $type !== Plugin::TYPE_FEATURE) {
            return response()->json(['data' => []]);
        }

        $installedPlugins = Plugin::byType(Plugin::TYPE_FEATURE)
            ->get()
            ->keyBy('code')
            ->toArray();

        $plugins = [];
        $seenCodes = [];

        foreach ($this->pluginManager->getPluginPaths() as $pluginPath) {
            if (!File::exists($pluginPath)) {
                continue;
            }
            $directories = File::directories($pluginPath);
            foreach ($directories as $directory) {
                $configFile = $directory . '/config.json';
                if (!File::exists($configFile)) {
                    continue;
                }
                $config = json_decode(File::get($configFile), true);
                if (!is_array($config) || !isset($config['code'])) {
                    continue;
                }
                $code = $config['code'];

                if (isset($seenCodes[$code])) {
                    continue;
                }
                $seenCodes[$code] = true;

                $pluginType = $config['type'] ?? Plugin::TYPE_FEATURE;
                if ($pluginType !== Plugin::TYPE_FEATURE) {
                    continue;
                }

                $installed = isset($installedPlugins[$code]);
                $pluginConfig = $installed ? $this->configService->getConfig($code) : ($config['config'] ?? []);
                $readmeFile = collect(['README.md', 'readme.md'])
                    ->map(fn($f) => $directory . '/' . $f)
                    ->first(fn($path) => File::exists($path));
                $readmeContent = $readmeFile ? File::get($readmeFile) : '';
                $needUpgrade = false;
                if ($installed) {
                    $installedVersion = $installedPlugins[$code]['version'] ?? null;
                    $localVersion = $config['version'] ?? null;
                    if ($installedVersion && $localVersion && version_compare($localVersion, $installedVersion, '>')) {
                        $needUpgrade = true;
                    }
                }
                $isCore = $this->pluginManager->isCorePlugin($code);
                $plugins[] = [
                    'code' => $config['code'],
                    'name' => $config['name'],
                    'version' => $config['version'],
                    'description' => $config['description'],
                    'author' => $config['author'],
                    'type' => $pluginType,
                    'is_installed' => $installed,
                    'is_enabled' => $installed ? $installedPlugins[$code]['is_enabled'] : false,
                    'is_protected' => $isCore,
                    'can_be_deleted' => !$isCore,
                    'config' => $pluginConfig,
                    'readme' => $readmeContent,
                    'need_upgrade' => $needUpgrade,
                    'admin_menus' => $config['admin_menus'] ?? null,
                    'admin_crud' => $config['admin_crud'] ?? null,
                ];
            }
        }

        return response()->json([
            'data' => $plugins
        ]);
    }

    /**
     * 安装插件
     */
    public function install(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        try {
            $this->pluginManager->install($request->input('code'));
            return response()->json([
                'message' => '插件安装成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件安装失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 卸载插件
     */
    public function uninstall(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $code = $request->input('code');
        $plugin = Plugin::where('code', $code)->first();
        if ($plugin && $plugin->is_enabled) {
            return response()->json([
                'message' => '请先禁用插件后再卸载'
            ], 400);
        }

        try {
            $this->pluginManager->uninstall($code);
            return response()->json([
                'message' => '插件卸载成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件卸载失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 升级插件
     */
    public function upgrade(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);
        try {
            $this->pluginManager->update($request->input('code'));
            return response()->json([
                'message' => '插件升级成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件升级失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 启用插件
     */
    public function enable(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        try {
            $this->pluginManager->enable($request->input('code'));
            return response()->json([
                'message' => '插件启用成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件启用失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 禁用插件
     */
    public function disable(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $this->pluginManager->disable($request->input('code'));
        return response()->json([
            'message' => '插件禁用成功'
        ]);

    }

    /**
     * 获取插件配置
     */
    public function getConfig(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        try {
            $this->assertFeaturePlugin($request->input('code'));
            $config = $this->configService->getConfig($request->input('code'));
            return response()->json([
                'data' => $config
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '获取配置失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 更新插件配置
     */
    public function updateConfig(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'config' => 'required|array'
        ]);

        try {
            $this->assertFeaturePlugin($request->input('code'));
            $this->configService->updateConfig(
                $request->input('code'),
                $request->input('config')
            );

            return response()->json([
                'message' => '配置更新成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '配置更新失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Keep retired plugin settings archived even when their files survive an upgrade.
     */
    private function assertFeaturePlugin(string $code): void
    {
        $configFile = $this->pluginManager->getPluginPath($code) . '/config.json';
        $config = File::exists($configFile) ? json_decode(File::get($configFile), true) : null;
        if (!is_array($config) || ($config['type'] ?? Plugin::TYPE_FEATURE) !== Plugin::TYPE_FEATURE) {
            throw new \InvalidArgumentException('插件不存在或类型不受支持');
        }

        $plugin = Plugin::where('code', $code)->first();
        if ($plugin && !$plugin->isFeaturePlugin()) {
            throw new \InvalidArgumentException('插件类型不受支持');
        }
    }

    /**
     * 上传插件
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:zip',
                'max:10240', // 最大10MB
            ]
        ], [
            'file.required' => '请选择插件包文件',
            'file.file' => '无效的文件类型',
            'file.mimes' => '插件包必须是zip格式',
            'file.max' => '插件包大小不能超过10MB'
        ]);

        try {
            $this->pluginManager->upload($request->file('file'));
            return response()->json([
                'message' => '插件上传成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件上传失败：' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * 删除插件
     */
    public function delete(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $code = $request->input('code');

        // 检查是否为核心插件
        if ($this->pluginManager->isCorePlugin($code)) {
            return response()->json([
                'message' => '该插件为系统核心插件，不允许删除'
            ], 403);
        }

        try {
            $this->pluginManager->delete($code);
            return response()->json([
                'message' => '插件删除成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => '插件删除失败：' . $e->getMessage()
            ], 400);
        }
    }
}
