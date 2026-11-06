<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class BuildConfigurationTest extends TestCase
{
    private function root(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }

    public function test_pushes_and_pull_requests_build_without_registry_write_permissions(): void
    {
        $workflow = Yaml::parseFile($this->root('.github/workflows/ci.yml'));
        $this->assertArrayHasKey('push', $workflow['on']);
        $this->assertArrayHasKey('pull_request', $workflow['on']);
        $this->assertSame(['contents' => 'read'], $workflow['permissions']);
        $builds = 0;
        foreach ($workflow['jobs'] as $job) {
            $this->assertArrayNotHasKey('permissions', $job);
            foreach ($job['steps'] as $step) {
                $this->assertStringNotContainsString('docker/login-action', $step['uses'] ?? '');
                $this->assertDoesNotMatchRegularExpression('/\bdocker\s+push\b/', $step['run'] ?? '');
                if (str_starts_with($step['uses'] ?? '', 'docker/build-push-action@')) {
                    $builds++;
                    $this->assertSame(false, $step['with']['push']);
                    $this->assertSame('.', $step['with']['context']);
                }
            }
        }
        $this->assertGreaterThan(0, $builds);
    }

    public function test_registry_publication_requires_an_explicit_dispatch_and_tag(): void
    {
        $workflow = Yaml::parseFile($this->root('.github/workflows/docker-publish.yml'));
        $this->assertSame(['workflow_dispatch'], array_keys($workflow['on']));
        $this->assertTrue($workflow['on']['workflow_dispatch']['inputs']['tag']['required']);
        $this->assertArrayNotHasKey('default', $workflow['on']['workflow_dispatch']['inputs']['tag']);
        $builds = array_filter($workflow['jobs']['publish']['steps'],
            fn ($step) => str_starts_with($step['uses'] ?? '', 'docker/build-push-action@'));
        $this->assertCount(1, $builds);
        $build = array_values($builds)[0]['with'];
        $this->assertTrue($build['push']);
        $this->assertSame('.', $build['context']);
        foreach (array_filter(explode("\n", trim($build['tags']))) as $tag) {
            $this->assertStringStartsWith('ghcr.io/coderdoubleflower/simplexboard:', $tag);
        }
    }

    public function test_image_and_compose_use_local_fork_source_and_persist_backups(): void
    {
        $dockerfile = file_get_contents($this->root('Dockerfile'));
        $this->assertStringContainsString('COPY . /www', $dockerfile);
        $this->assertStringContainsString('COPY panel/package.json panel/package-lock.json', $dockerfile);
        $this->assertStringContainsString('COPY --from=panel /build/public/assets/panel', $dockerfile);
        $this->assertStringContainsString('composer install --no-dev', $dockerfile);
        $this->assertDoesNotMatchRegularExpression('/git (?:clone|fetch|reset|pull)|composer update|REPO_URL|BRANCH_NAME/', $dockerfile);

        $ignored = file_get_contents($this->root('.dockerignore'));
        $this->assertDoesNotMatchRegularExpression('~^/?(?:composer\.lock|panel(?:/package-lock\.json)?|public/assets/panel)/?$~m', $ignored);
        foreach (glob($this->root('compose*.sample.yaml')) as $file) {
            $compose = Yaml::parseFile($file);
            $apps = 0;
            foreach ($compose['services'] as $service) {
                if (!str_contains($service['image'], 'simplexboard')) {
                    continue;
                }
                $apps++;
                $this->assertSame('ghcr.io/coderdoubleflower/simplexboard:local', $service['image']);
                $this->assertSame('.', $service['build']['context']);
                $this->assertContains('./storage/backup:/www/storage/backup', $service['volumes']);
                $volumes = implode("\n", $service['volumes']);
                $this->assertStringNotContainsString('/plugins', $volumes);
                $this->assertStringNotContainsString('/theme', $volumes);
            }
            $this->assertGreaterThan(0, $apps, basename($file));
        }
    }

    public function test_startup_never_migrates_or_fetches_code_and_upgrade_keeps_lock_files(): void
    {
        $entrypoint = file_get_contents($this->root('.docker/entrypoint.sh'));
        $this->assertDoesNotMatchRegularExpression('/artisan (?:migrate|xboard:update)|git (?:clone|fetch|reset|pull)/', $entrypoint);
        foreach (['init.sh', 'update.sh'] as $file) {
            $script = file_get_contents($this->root($file));
            $this->assertStringContainsString('composer install --no-dev', $script);
            $this->assertStringContainsString('npm --prefix panel ci', $script);
            $this->assertDoesNotMatchRegularExpression('/composer(?:\.phar)? update|rm[^\n]+composer\.lock|git (?:clone|fetch|reset|pull|submodule)/', $script);
        }
    }
}
