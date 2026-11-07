<?php

namespace Tests\Feature\Server;

use App\Models\AdminAuditLog;
use App\Models\Server;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ParagonIE_Sodium_Compat;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RealityKeyGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $prefix;
    private string $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = $this->user(['is_admin' => true]);
        $this->auth = (new AuthService($this->admin))->generateAuthData()['auth_data'];
        $this->prefix = '/api/v2/' . hash('crc32b', config('app.key')) . '/server/manage/';
    }

    public static function generationEndpoints(): array
    {
        return [
            'REALITY key pair' => ['generateRealityKeyPair'],
            'Short ID' => ['generateShortId'],
        ];
    }

    #[DataProvider('generationEndpoints')]
    public function test_generation_requires_post_and_an_active_admin_session(string $endpoint): void
    {
        $member = $this->user();
        $banned = $this->user(['is_admin' => true, 'banned' => true]);
        $credentials = [
            '',
            'Bearer invalid-token',
            'Bearer ' . $member->createToken('member')->plainTextToken,
            'Bearer ' . $banned->createToken('banned-admin')->plainTextToken,
            'Bearer ' . $this->admin->createToken('expired-admin', ['admin'], now()->subMinute())->plainTextToken,
        ];
        foreach ($credentials as $credential) {
            app('auth')->forgetGuards();
            $this->withHeader('Authorization', $credential)->postJson($this->prefix . $endpoint)
                ->assertForbidden()->assertJsonMissingPath('data.private_key')->assertJsonMissingPath('data.short_id');
        }

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', $this->auth)
            ->postJson('/api/v2/wrong-admin-path/server/manage/' . $endpoint)->assertNotFound();
        $this->getJson($this->prefix . $endpoint)->assertStatus(405);
        $this->assertDatabaseCount('v2_admin_audit_log', 0);
    }

    public static function cryptoImplementations(): array
    {
        return [
            'default implementation' => [false],
            'PHP implementation without sodium extension' => [true],
        ];
    }

    #[DataProvider('cryptoImplementations')]
    public function test_reality_generation_returns_distinct_canonical_key_pairs_without_a_node(bool $forcePhp): void
    {
        $original = ParagonIE_Sodium_Compat::$disableFallbackForUnitTests;
        $pairs = [];
        try {
            // The library's test switch bypasses both native sodium variants.
            ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = $forcePhp;
            for ($i = 0; $i < 3; $i++) {
                $response = $this->withHeader('Authorization', $this->auth)
                    ->postJson($this->prefix . 'generateRealityKeyPair')->assertOk()->assertJsonPath('status', 'success');
                $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
                $data = $response->json('data');
                $this->assertEqualsCanonicalizing(['public_key', 'private_key', 'short_id'], array_keys($data));
                $privateKey = $this->decodeKey($data['private_key']);
                $publicKey = $this->decodeKey($data['public_key']);
                $this->assertSame(0, ord($privateKey[0]) & 7);
                $this->assertSame(0, ord($privateKey[31]) & 128);
                $this->assertSame(64, ord($privateKey[31]) & 64);
                $derived = function_exists('sodium_crypto_scalarmult_base')
                    ? sodium_crypto_scalarmult_base($privateKey)
                    : ParagonIE_Sodium_Compat::crypto_scalarmult_base($privateKey);
                $this->assertSame($derived, $publicKey);
                $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $data['short_id']);
                $pairs[] = $data;
            }
        } finally {
            ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = $original;
        }

        foreach (['public_key', 'private_key', 'short_id'] as $field) {
            $this->assertCount(3, array_unique(array_column($pairs, $field)));
        }
        $this->assertDatabaseCount('v2_server', 0);
    }

    public function test_short_id_generation_is_independent_and_returns_distinct_ids(): void
    {
        $shortIds = [];
        for ($i = 0; $i < 3; $i++) {
            $response = $this->withHeader('Authorization', $this->auth)
                ->postJson($this->prefix . 'generateShortId')->assertOk()->assertJsonPath('status', 'success');
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $this->assertSame(['short_id'], array_keys($response->json('data')));
            $shortIds[] = $shortId = $response->json('data.short_id');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $shortId);
            $this->assertSame(8, strlen(hex2bin($shortId)));
        }
        $this->assertCount(3, array_unique($shortIds));
        $this->assertDatabaseCount('v2_server', 0);
    }

    public function test_generation_audits_only_the_request_and_preserves_existing_nodes_and_settings(): void
    {
        $node = Server::create([
            'name' => 'Existing REALITY node', 'type' => 'vless', 'host' => 'node.example.test',
            'port' => 443, 'server_port' => 443, 'enabled' => true, 'show' => true,
            'protocol_settings' => ['tls' => 2, 'reality_settings' => [
                'public_key' => 'existing-public-key', 'private_key' => 'existing-private-key', 'short_id' => '0102030405060708',
            ]],
        ]);
        $nodesBefore = DB::table('v2_server')->orderBy('id')->get()->toJson();
        $settingsBefore = DB::table('v2_settings')->orderBy('id')->get()->toJson();
        $generated = [];
        foreach (['generateRealityKeyPair', 'generateShortId'] as $endpoint) {
            $response = $this->withHeader('Authorization', $this->auth)
                ->postJson($this->prefix . $endpoint, ['id' => $node->id])->assertOk();
            $generated = array_merge($generated, array_values($response->json('data')));
            $audit = AdminAuditLog::latest('id')->firstOrFail();
            $this->assertSame($this->admin->id, $audit->admin_id);
            $this->assertSame('POST', $audit->method);
            $this->assertSame('server_manage.' . $endpoint, $audit->action);
            $this->assertSame($this->prefix . $endpoint, $audit->uri);
            $this->assertSame(['id' => $node->id], json_decode($audit->request_data, true));
        }

        $this->assertSame($nodesBefore, DB::table('v2_server')->orderBy('id')->get()->toJson());
        $this->assertSame($settingsBefore, DB::table('v2_settings')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('v2_admin_audit_log', 2);
        $auditJson = AdminAuditLog::all()->toJson();
        foreach ($generated as $value) {
            $this->assertStringNotContainsString($value, $auditJson);
        }
    }

    private function decodeKey(string $encoded): string
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $encoded);
        $decoded = base64_decode(strtr($encoded, '-_', '+/') . '=', true);
        $this->assertIsString($decoded);
        $this->assertSame(32, strlen($decoded));
        $this->assertSame($encoded, Helper::base64EncodeUrlSafe($decoded));
        return $decoded;
    }

    private function user(array $attributes = []): User
    {
        return User::query()->forceCreate($attributes + [
            'email' => Helper::guid() . '@example.test', 'is_admin' => false, 'banned' => false,
            'uuid' => Helper::guid(true), 'token' => Helper::guid(),
        ]);
    }
}
