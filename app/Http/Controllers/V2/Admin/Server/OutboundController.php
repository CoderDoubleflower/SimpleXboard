<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerOutbound;
use App\Services\ServerOutboundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OutboundController extends Controller
{
    public function fetch()
    {
        $outbounds = ServerOutbound::with('servers:id,name,outbound_id')->orderBy('id')->get();
        return $this->success($outbounds->map(function (ServerOutbound $outbound) {
            $settings = $outbound->settings;
            return [
                'id' => $outbound->id, 'name' => $outbound->name, 'type' => $outbound->type,
                'server' => $settings['server'], 'server_port' => $settings['server_port'],
                'notes' => $outbound->notes, 'usage_count' => $outbound->servers->count(),
                'used_by' => $outbound->servers->map(fn ($node) => ['id' => $node->id, 'name' => $node->name])->values(),
            ];
        }));
    }

    public function get(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|exists:v2_server_outbound,id']);
        $outbound = ServerOutbound::findOrFail($params['id']);
        return $this->success([
            'id' => $outbound->id, 'name' => $outbound->name, 'type' => $outbound->type,
            'settings' => $outbound->settings, 'notes' => $outbound->notes,
        ])->header('Cache-Control', 'no-store');
    }

    public function import(Request $request, ServerOutboundService $service)
    {
        $params = $request->validate(['uri' => 'required|string|max:8192']);
        return $this->success($service->import($params['uri']))->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, ServerOutboundService $service)
    {
        $params = $request->validate([
            'id' => 'sometimes|integer|exists:v2_server_outbound,id',
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
            'type' => 'required|in:' . implode(',', ServerOutboundService::TYPES),
            'settings' => 'required|array',
            'notes' => 'nullable|string|max:2000',
        ]);
        $params['settings'] = $service->validateSettings($params['type'], $params['settings']);
        $outbound = DB::transaction(function () use ($params) {
            $outbound = isset($params['id']) ? ServerOutbound::lockForUpdate()->findOrFail($params['id']) : new ServerOutbound;
            unset($params['id']);
            $outbound->fill($params)->save();
            return $outbound;
        });
        return $this->success(['id' => $outbound->id]);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|exists:v2_server_outbound,id']);
        DB::transaction(function () use ($params) {
            $outbound = ServerOutbound::lockForUpdate()->findOrFail($params['id']);
            if ($outbound->servers()->exists()) {
                throw ValidationException::withMessages(['id' => '此出站仍被节点使用，请先修改关联节点的出站。']);
            }
            $outbound->delete();
        });
        return $this->success(true);
    }
}
