<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\ServerMachine;

/** Generates commands only. Execution and machine changes remain explicit actions. */
class NodeMaintenanceService
{
    private const REPOSITORY = 'CoderDoubleflower/SimpleXboard';
    private const SCRIPT = 'node-manager.sh';
    private const DOWNLOAD_RUNNER = 'set -e; f=$(mktemp); trap \'rm -f -- "$f"\' EXIT; curl -fsSL --retry 3 "$1" -o "$f"; shift; bash "$f" "$@"';

    public function commands(ServerMachine $machine, string $panelUrl): array
    {
        $release = $this->release();
        $url = 'https://github.com/' . $release['repository'] . '/releases/download/' . $release['tag'] . '/' . $release['script'];

        return [
            'version' => $release['version'],
            'platforms' => $release['platforms'],
            'install_command' => $this->command($url, [
                'install', '--panel', rtrim($panelUrl, '/'), '--token', (string) $machine->token,
                '--machine-id', (string) $machine->id,
            ]),
            'upgrade_command' => $this->command($url, ['upgrade']),
            'uninstall_command' => $this->command($url, ['uninstall']),
            'purge_command' => $this->command($url, ['uninstall', '--purge']),
        ];
    }

    private function command(string $url, array $arguments): string
    {
        $arguments = [$url, ...$arguments];
        foreach ($arguments as $argument) {
            // Quoting cannot make an embedded newline a one-line copyable command.
            if (preg_match('/[\x00-\x1f\x7f]/', $argument)) {
                throw new ApiException('面板地址或机器凭据包含控制字符，无法生成单行维护命令。请检查配置。', 422);
            }
        }

        return 'bash -c ' . escapeshellarg(self::DOWNLOAD_RUNNER) . ' -- '
            . implode(' ', array_map('escapeshellarg', $arguments));
    }

    private function release(): array
    {
        $path = resource_path('node/release.json');
        if (!is_readable($path)) $this->invalidRelease();
        try {
            $release = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->invalidRelease();
        }
        if (!is_array($release)
            || !is_string($release['version'] ?? null)
            || !preg_match('/\Av[0-9][A-Za-z0-9._-]*\z/', $release['version'])
            || ($release['tag'] ?? null) !== 'node-' . $release['version']
            || ($release['repository'] ?? null) !== self::REPOSITORY
            || ($release['script'] ?? null) !== self::SCRIPT
            || !is_array($release['platforms'] ?? null)
            || !array_is_list($release['platforms'])
            || $release['platforms'] === []
            || array_filter($release['platforms'], fn ($platform) => !is_string($platform)
                || !in_array($platform, ['linux-amd64', 'linux-arm64'], true))) {
            $this->invalidRelease();
        }
        return $release;
    }

    private function invalidRelease(): never
    {
        throw new ApiException('节点发布配置不可用，请更新面板后重试。', 503);
    }
}
