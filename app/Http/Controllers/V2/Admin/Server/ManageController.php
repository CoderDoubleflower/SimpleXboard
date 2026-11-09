<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\ServerOutbound;
use App\Services\OutboundCapabilityService;
use App\Services\ServerCertificateService;
use App\Services\ServerOutboundService;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ParagonIE_Sodium_Compat;
use Illuminate\Validation\ValidationException;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers()->map(function ($item) {
            $item['parent'] = $item->parent;
            return $item;
        });
        return $this->success($servers);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        try {
            DB::transaction(function () use ($params) {
                $server = isset($params['id']) ? Server::lockForUpdate()->findOrFail($params['id']) : null;
                $certificate = app(ServerCertificateService::class)->validateForNode($params, $server);
                if ($params['type'] === Server::TYPE_HYSTERIA) $params['cert_config'] = $certificate;
                app(ServerOutboundService::class)->assertSelection($params, $server);
                $outboundId = array_key_exists('outbound_id', $params) ? $params['outbound_id'] : $server?->outbound_id;
                $machineId = array_key_exists('machine_id', $params) ? $params['machine_id'] : $server?->machine_id;
                OutboundCapabilityService::validateSelection($server, $outboundId === null ? null : (int) $outboundId, $machineId === null ? null : (int) $machineId);
                if ($outboundId && !ServerOutbound::lockForUpdate()->find($outboundId)) {
                    throw ValidationException::withMessages(['outbound_id' => '所选出站已不存在，请重新选择。']);
                }
                if ($server) $server->update($params);
                else Server::create($params);
            });
            return $this->success(true);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, isset($params['id']) ? '保存失败' : '创建失败']);
        }
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'show' => 'sometimes|boolean',
            'machine_id' => 'nullable|integer|exists:v2_server_machine,id',
            'enabled' => 'sometimes|boolean',
        ]);

        return DB::transaction(function () use ($params) {
            $server = Server::lockForUpdate()->find($params['id']);
            if (!$server) return $this->fail([400202, '服务器不存在']);
            if ((bool) ($params['enabled'] ?? $server->enabled)) {
                app(ServerCertificateService::class)->validateForNode([], $server);
            }
            $machineId = array_key_exists('machine_id', $params) ? $params['machine_id'] : $server->machine_id;
            OutboundCapabilityService::validateSelection($server, $server->outbound_id, $machineId === null ? null : (int) $machineId);
            if (array_key_exists('show', $params)) $server->show = (int) $params['show'];
            if (array_key_exists('machine_id', $params)) $server->machine_id = $params['machine_id'] ?: null;
            if (array_key_exists('enabled', $params)) $server->enabled = (bool) $params['enabled'];
            if (!$server->save()) return $this->fail([500, '保存失败']);
            return $this->success(true);
        });
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        if ($server->delete() === false) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    /**
     * 批量删除节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要删除的节点']);
        }

        try {
            $deleted = DB::transaction(fn () => Server::whereIn('id', $ids)->get()->each->delete());
            if ($deleted === false) {
                return $this->fail([500, '批量删除失败']);
            }
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量删除失败']);
        }
    }

    /**
     * 重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetTraffic(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        try {
            $server->u = 0;
            $server->d = 0;
            $server->save();
            
            Log::info("Server {$server->id} ({$server->name}) traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '重置失败']);
        }
    }

    /**
     * 批量重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchResetTraffic(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要重置的节点']);
        }

        try {
            DB::transaction(fn () => Server::whereIn('id', $ids)->get()->each->update(['u' => 0, 'd' => 0]));
            
            Log::info("Servers " . implode(',', $ids) . " traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量重置失败']);
        }
    }

    /**
     * 批量更新节点属性（show等）
     */
    public function batchUpdate(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'show' => 'sometimes|boolean',
            'enabled' => 'sometimes|boolean',
            'machine_id' => 'nullable|integer|exists:v2_server_machine,id',
        ]);

        $ids = $params['ids'];
        if (empty($ids)) {
            return $this->fail([400, '请选择要更新的节点']);
        }

        $update = [];
        if (array_key_exists('show', $params) && $params['show'] !== null) {
            $update['show'] = (int) $params['show'];
        }
        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $update['enabled'] = (bool) $params['enabled'];
        }
        if (array_key_exists('machine_id', $params)) {
            $update['machine_id'] = $params['machine_id'] ?: null;
        }

        if (empty($update)) {
            return $this->fail([400, '没有可更新的字段']);
        }

        try {
            DB::transaction(function () use ($ids, $update) {
                $servers = Server::whereIn('id', $ids)->lockForUpdate()->get();
                /** @var Server $server */
                foreach ($servers as $server) {
                    if ((bool) ($update['enabled'] ?? $server->enabled)) {
                        app(ServerCertificateService::class)->validateForNode([], $server);
                    }
                    $machineId = array_key_exists('machine_id', $update) ? $update['machine_id'] : $server->machine_id;
                    OutboundCapabilityService::validateSelection($server, $server->outbound_id, $machineId === null ? null : (int) $machineId);
                }
                foreach ($servers as $server) $server->update($update);
            });
            return $this->success(true);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量更新失败']);
        }
    }

    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        $copiedServer = $server->replicate();
        $copiedServer->show = false;
        $copiedServer->enabled = false;
        $copiedServer->code = null;
        $copiedServer->u = 0;
        $copiedServer->d = 0;
        $copiedServer->save();

        return $this->success(true);
    }

    public function generateRealityKeyPair(): JsonResponse
    {
        $privateKey = random_bytes(32);
        // Export the canonical X25519 scalar (RFC 7748, section 5), not its
        // equivalent unclamped representation. Sodium also clamps on use.
        $privateKey[0] = chr(ord($privateKey[0]) & 248);
        $privateKey[31] = chr((ord($privateKey[31]) & 127) | 64);
        $publicKey = ParagonIE_Sodium_Compat::crypto_scalarmult_base($privateKey);

        return $this->success([
            'public_key' => Helper::base64EncodeUrlSafe($publicKey),
            'private_key' => Helper::base64EncodeUrlSafe($privateKey),
            'short_id' => bin2hex(random_bytes(8)),
        ])->header('Cache-Control', 'no-store');
    }

    public function generateShortId(): JsonResponse
    {
        return $this->success([
            'short_id' => bin2hex(random_bytes(8)),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Generate ECH (Encrypted Client Hello) key pair.
     * Returns PEM-encoded ECH key (server-side) and ECH config (client-side).
     */
    public function generateEchKey(Request $request)
    {
        $publicName = $request->input('public_name', 'ech.example.com');
        if (strlen($publicName) < 1 || strlen($publicName) > 253) {
            throw new ApiException('public_name must be a valid domain (1-253 bytes)');
        }

        // Generate X25519 key pair
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // Build ECHConfigContents (draft-ietf-tls-esni-18)
        $contents = '';
        $contents .= pack('C', $configId);                // config_id
        $contents .= pack('n', 0x0020);                   // kem_id: DHKEM(X25519)
        $contents .= pack('n', 32) . $publicKey;          // public_key (length-prefixed)
        // cipher_suites: 2 suites × 4 bytes = 8 bytes
        $contents .= pack('n', 8);                        // cipher_suites byte length
        $contents .= pack('nn', 0x0001, 0x0001);          // HKDF-SHA256 + AES-128-GCM
        $contents .= pack('nn', 0x0001, 0x0003);          // HKDF-SHA256 + ChaCha20Poly1305
        $contents .= pack('C', 0);                        // max_name_length
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);                        // extensions: empty

        // ECHConfig = version(2) + length(2) + contents
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;

        // ECHConfigList = total_length(2) + configs
        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;

        // ECH Keys = private_key_len(2) + key(32) + config_len(2) + config
        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        $keyPem = "-----BEGIN ECH KEYS-----\n"
            . chunk_split(base64_encode($echKeysPayload), 64, "\n")
            . "-----END ECH KEYS-----";

        $configPem = "-----BEGIN ECH CONFIGS-----\n"
            . chunk_split(base64_encode($echConfigList), 64, "\n")
            . "-----END ECH CONFIGS-----";

        return $this->success([
            'key' => $keyPem,
            'config' => $configPem,
        ]);
    }
}
