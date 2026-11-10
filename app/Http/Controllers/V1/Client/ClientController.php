<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Exceptions\ApiException;
use App\Models\Server;
use App\Protocols\ClashMeta;
use App\Protocols\Stash;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    /**
     * Protocol prefix mapping for server names
     */
    private const PROTOCOL_PREFIXES = [
        'hysteria' => [
            1 => '[Hy]',
            2 => '[Hy2]'
        ],
        'vless' => '[vless]',
        'shadowsocks' => '[ss]',
        'vmess' => '[vmess]',
        'trojan' => '[trojan]',
        'tuic' => '[tuic]',
        'socks' => '[socks]',
        'anytls' => '[anytls]'
    ];


    public function subscribe(Request $request)
    {
        $request->validate([
            'types' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
            'flag' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $userService = new UserService();

        if (!$userService->isAvailable($user)) {
            return response('', 403, ['Content-Type' => 'text/plain']);
        }

        try {
            return $this->doSubscribe($request, $user);
        } catch (ValidationException $exception) {
            // A public client cannot repair administrator-authored references.
            // Fail closed without redirecting or revealing hidden node details;
            // admin preview calls doSubscribe directly to retain field errors.
            throw new ApiException('订阅模板配置不可用，请联系管理员检查链式代理和规则引用。', 422);
        }
    }

    public function doSubscribe(Request $request, $user, $servers = null)
    {
        if ($servers === null) {
            $servers = ServerService::getAvailableServers($user);
        }

        $clientInfo = $this->getClientInfo($request);

        $requestedTypes = $this->parseRequestedTypes($request->input('types'));
        $filterKeywords = $this->parseFilterKeywords($request->input('filter'));

        $protocolClassName = $clientInfo['format'] === 'stash' ? Stash::class : ClashMeta::class;

        $serversFiltered = $this->filterServers(
            servers: $servers,
            allowedTypes: $requestedTypes,
            filterKeywords: $filterKeywords
        );

        $serversFiltered = $this->addPrefixToServerName($serversFiltered);

        // Instantiate the protocol class with filtered servers and client info
        $protocolInstance = app()->make($protocolClassName, [
            'user' => $user,
            'servers' => $serversFiltered,
            'clientName' => $clientInfo['name'] ?? null,
            'clientVersion' => $clientInfo['version'] ?? null,
            'userAgent' => $request->header('User-Agent', '')
        ]);

        return $protocolInstance->handle();
    }

    /**
     * Parses the input string for requested server types.
     */
    private function parseRequestedTypes(?string $typeInputString): array
    {
        if (blank($typeInputString) || $typeInputString === 'all') {
            return Server::VALID_TYPES;
        }

        $requested = collect(preg_split('/[|,｜]+/', $typeInputString))
            ->map(fn($type) => trim($type))
            ->filter() // Remove empty strings that might result from multiple delimiters
            ->all();

        return array_values(array_intersect($requested, Server::VALID_TYPES));
    }

    /**
     * Parses the input string for filter keywords.
     */
    private function parseFilterKeywords(?string $filterInputString): ?array
    {
        if (blank($filterInputString) || mb_strlen($filterInputString) > 20) {
            return null;
        }

        return collect(preg_split('/[|,｜]+/', $filterInputString))
            ->map(fn($keyword) => trim($keyword))
            ->filter() // Remove empty strings
            ->all();
    }

    /**
     * Filters servers based on allowed types and keywords.
     */
    private function filterServers(array $servers, array $allowedTypes, ?array $filterKeywords): array
    {
        return collect($servers)->filter(function ($server) use ($allowedTypes, $filterKeywords) {
            // Condition 1: Server type must be in the list of allowed types
            if (!in_array($server['type'], $allowedTypes)) {
                return false; // Filter out (don't keep)
            }

            // Condition 2: If filterKeywords are provided, at least one keyword must match
            if (!empty($filterKeywords)) { // Check if $filterKeywords is not empty
                $keywordMatch = collect($filterKeywords)->contains(function ($keyword) use ($server) {
                    return stripos($server['name'], $keyword) !== false
                        || in_array($keyword, $server['tags'] ?? []);
                });
                if (!$keywordMatch) {
                    return false; // Filter out if no keywords match
                }
            }
            // Keep the server if its type is allowed AND (no filter keywords OR at least one keyword matched)
            return true;
        })->values()->all();
    }

    private function getClientInfo(Request $request): array
    {
        $manager = app('protocols.manager');
        $userAgent = strtolower($request->header('User-Agent', ''));
        $flag = $request->input('flag');
        $hasExplicitFlag = is_string($flag) && trim($flag) !== '';

        if ($hasExplicitFlag) {
            $format = $manager->normalizeFlag($flag);
            if ($format === null) {
                throw new ApiException('订阅仅支持 mihomo 与 Stash，请使用 flag=mihomo 或 flag=stash。', 422);
            }
        } else {
            // Recognized retired clients receive an actionable error instead of
            // accidentally importing a config belonging to a different engine.
            if (preg_match('/(?:^|[^a-z0-9])(?:sing[- ]?box|sagernet|surge|surfboard|quantumultx?|shadowrocket|loon|v2rayng?|nekobox|nekoray|passwall|ssrplus|shadowsocks)(?:[^a-z0-9]|$)/i', $userAgent)) {
                throw new ApiException('该客户端的订阅格式已停用，目前仅支持 mihomo 与 Stash。', 422);
            }
            $format = $manager->matchProtocolClassName($userAgent) === Stash::class ? 'stash' : 'mihomo';
        }

        // The query chooses the format; the real UA supplies its version. In
        // particular flag=stash must not erase Stash/3.x feature compatibility.
        $clientName = $format === 'stash' ? 'stash' : 'meta';
        $clientVersion = null;
        $patterns = $format === 'stash'
            ? ['stash' => 'stash(?:[ _-](?:macos|mac|ios|tvos))?']
            : ['meta' => 'mihomo|clash[ ._-]?meta|meta', 'clashmetaforandroid' => 'clashmetaforandroid',
                'flclash' => 'flclash', 'verge' => '(?:clash[ ._-]?)?verge', 'ClashX Meta' => 'clashx[ ._-]?meta'];
        foreach ($patterns as $name => $pattern) {
            if (preg_match('~(?:^|[^a-z0-9])(?:' . $pattern . ')[/\s]+v?(\d+(?:\.\d+){0,2})(?:[^\d.]|$)~i', $userAgent, $matches)) {
                $clientName = $name;
                $clientVersion = $matches[1];
                break;
            }
        }

        return ['format' => $format, 'name' => $clientName, 'version' => $clientVersion];
    }



    private function addPrefixToServerName(array $servers): array
    {
        if (!admin_setting('show_protocol_to_server_enable', false)) {
            return $servers;
        }
        return collect($servers)
            ->map(function (array $server): array {
                $server['name'] = $this->getPrefixedServerName($server);
                return $server;
            })
            ->all();
    }

    private function getPrefixedServerName(array $server): string
    {
        $type = $server['type'] ?? '';
        if (!isset(self::PROTOCOL_PREFIXES[$type])) {
            return $server['name'] ?? '';
        }
        $prefix = is_array(self::PROTOCOL_PREFIXES[$type])
            ? self::PROTOCOL_PREFIXES[$type][$server['protocol_settings']['version'] ?? 1] ?? ''
            : self::PROTOCOL_PREFIXES[$type];
        return $prefix . ($server['name'] ?? '');
    }
}
