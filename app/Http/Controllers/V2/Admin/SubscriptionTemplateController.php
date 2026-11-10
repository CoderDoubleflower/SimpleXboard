<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Services\SubscriptionRuleCatalog;
use App\Services\SubscriptionTemplateImporter;
use App\Services\SubscriptionTemplateService;
use App\Services\SubscriptionTemplateValidator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubscriptionTemplateController extends Controller
{
    public function __construct(private SubscriptionTemplateService $templates) {}

    public function fetch(Request $request)
    {
        $client = $this->client($request);
        $profile = $this->templates->getProfile($client);
        $warnings = [];
        try {
            $config = app(SubscriptionTemplateValidator::class)->yaml($profile['base_yaml']);
        } catch (ValidationException) {
            // A legacy invalid template must remain editable and replaceable.
            $config = ['proxies' => [], 'proxy-groups' => []];
            $warnings[] = '原有基础 YAML 需要修正；内容已保留，可编辑或导入替换，保存前会重新校验。';
        }
        $policies = array_merge([$profile['default_policy'], 'DIRECT', 'REJECT'], array_column($config['proxy-groups'], 'name'), array_column($config['proxies'], 'name'));
        $policies = array_map(fn ($name) => str_replace('$app_name', admin_setting('app_name', 'SimpleXboard'), $name), $policies);
        $targets = [];
        foreach (['proxy-groups' => 'group', 'proxies' => 'proxy'] as $section => $type) {
            foreach ($config[$section] as $item) {
                $name = str_replace('$app_name', admin_setting('app_name', 'SimpleXboard'), $item['name']);
                $targets[] = ['ref' => $type . ':' . $name, 'name' => $name, 'type' => $type];
            }
        }
        $default = str_replace('$app_name', admin_setting('app_name', 'SimpleXboard'), $profile['default_policy']);
        if (!in_array($default, array_column($targets, 'name'), true) && !in_array($default, SubscriptionTemplateValidator::BUILTINS, true)) {
            $targets[] = ['ref' => 'group:' . $default, 'name' => $default, 'type' => 'group'];
        }
        return $this->success([
            'client' => $client, 'profile' => $profile,
            'warnings' => $warnings,
            'categories' => SubscriptionRuleCatalog::categories(), 'presets' => SubscriptionRuleCatalog::presets(),
            'policies' => array_values(array_unique($policies)),
            'chain_targets' => $targets,
            'nodes' => Server::query()->orderBy('sort')->orderBy('id')->get(['id', 'name', 'type', 'enabled', 'show'])
                ->map(fn ($node) => ['id' => $node->id, 'name' => $node->name, 'type' => $node->type, 'ref' => 'node:' . $node->id, 'enabled' => $node->enabled, 'show' => $node->show])->all(),
            'members' => User::query()->where('is_admin', false)->orderBy('id')->get(['id', 'name', 'email'])->toArray(),
        ])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request)
    {
        $client = $this->client($request);
        $request->validate(['profile' => ['required', 'array']]);
        return $this->success(['client' => $client, 'profile' => $this->templates->save($client, $request->input('profile'))])->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request)
    {
        $client = $this->client($request);
        $request->validate(['profile' => ['required', 'array'], 'user_id' => ['nullable', 'integer']]);
        $member = null;
        if ($request->filled('user_id')) {
            $member = User::query()->where('is_admin', false)->find($request->integer('user_id'));
            if (!$member) SubscriptionTemplateValidator::fail('user_id', '成员不存在。');
        }
        return $this->success($this->templates->preview($client, $request->input('profile'), $member))->header('Cache-Control', 'no-store');
    }

    public function import(Request $request, SubscriptionTemplateImporter $importer)
    {
        $client = $this->client($request);
        $request->validate(['content' => ['required', 'string', 'max:524288'], 'profile' => ['sometimes', 'array']]);
        $current = $request->has('profile') ? array_replace($this->templates->getProfile($client), $request->input('profile')) : $this->templates->getProfile($client);
        $result = $importer->import($request->input('content'), $current);
        // Import creates a reviewable draft; the user explicitly saves it afterwards.
        $result['profile'] = $this->templates->prepareProfile($client, $result['profile']);
        return $this->success(['client' => $client] + $result)->header('Cache-Control', 'no-store');
    }

    private function client(Request $request): string
    {
        $request->validate(['client' => ['required', 'string', Rule::in(['mihomo', 'stash'])]]);
        return $request->input('client');
    }
}
