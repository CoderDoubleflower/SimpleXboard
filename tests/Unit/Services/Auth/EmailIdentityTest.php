<?php

namespace Tests\Unit\Services\Auth;

use App\Http\Requests\Passport\AuthLogin;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmailIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_email_normalization_handles_unicode_and_international_domains(): void
    {
        $expected = 'josé@xn--fsqu00a.xn--fiqs8s';

        $this->assertSame($expected, User::normalizeEmail('JOSÉ@例子.中国'));
        $this->assertSame($expected, User::normalizeEmail("Jose\u{0301}@XN--FSQU00A.XN--FIQS8S"));
        $this->assertSame('δοκιμή@example.com', User::normalizeEmail('ΔΟΚΙΜΉ@EXAMPLE.COM'));
    }

    public function test_email_normalization_does_not_remove_accents(): void
    {
        $this->assertNotSame(
            User::normalizeEmail('jose@example.com'),
            User::normalizeEmail('josé@example.com')
        );
    }

    public function test_form_request_normalizes_email_before_validation(): void
    {
        $request = AuthLogin::create('/login', 'POST', [
            'email' => 'José@例子.中国',
            'password' => 'password123',
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        $this->assertSame('josé@xn--fsqu00a.xn--fiqs8s', $request->input('email'));
    }

    public function test_request_validation_checks_the_normalized_email_length(): void
    {
        $email = 'a@' . str_repeat('例', 20) . '.' . str_repeat('例', 20) . '.' . str_repeat('例', 20);
        $this->assertSame(64, mb_strlen($email, 'UTF-8'));
        $this->assertGreaterThan(64, mb_strlen(User::normalizeEmail($email), 'UTF-8'));

        $request = AuthLogin::create('/login', 'POST', [
            'email' => $email,
            'password' => 'password123',
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));

        try {
            $request->validateResolved();
            $this->fail('Expected the normalized email length to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
    }

    public function test_database_unique_index_distinguishes_different_email_identities(): void
    {
        $plain = $this->createUser('Jose@example.com');
        $accented = $this->createUser('José@example.com');

        $this->assertNotSame($plain->id, $accented->id);
        $this->assertSame('jose@example.com', $plain->email);
        $this->assertSame('josé@example.com', $accented->email);
    }

    public function test_database_unique_index_rejects_equivalent_unicode_email_identities(): void
    {
        $this->createUser('José@example.com');

        $this->expectException(QueryException::class);
        $this->createUser("Jose\u{0301}@EXAMPLE.COM");
    }

    public function test_email_lookup_uses_the_same_normalization_as_storage(): void
    {
        $user = $this->createUser('José@例子.中国');

        $found = User::byEmail("JOSE\u{0301}@XN--FSQU00A.XN--FIQS8S")->first();

        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);
    }

    public function test_login_accepts_equivalent_unicode_email_representation(): void
    {
        admin_setting('password_limit_enable', 0);
        $user = $this->createUser('José@example.com', 'old-password');

        [$success, $result] = app(LoginService::class)->login(
            "JOSE\u{0301}@EXAMPLE.COM",
            'old-password'
        );

        $this->assertTrue($success);
        $this->assertSame($user->id, $result->id);
    }

    public function test_migration_normalizes_existing_email_values(): void
    {
        $user = $this->createUser('legacy@example.com');
        \Illuminate\Support\Facades\DB::table('v2_user')
            ->where('id', $user->id)
            ->update(['email' => 'Legacy@EXAMPLE.COM']);

        $migration = require base_path('database/migrations/2026_11_02_000001_normalize_user_email_identity.php');
        $migration->up();

        $this->assertDatabaseHas('v2_user', [
            'id' => $user->id,
            'email' => 'legacy@example.com',
        ]);
    }

    public function test_migration_refuses_to_merge_existing_email_collisions(): void
    {
        $first = $this->createUser('first@example.com');
        $second = $this->createUser('second@example.com');

        \Illuminate\Support\Facades\DB::table('v2_user')->where('id', $first->id)
            ->update(['email' => 'José@example.com']);
        \Illuminate\Support\Facades\DB::table('v2_user')->where('id', $second->id)
            ->update(['email' => "Jose\u{0301}@example.com"]);

        $migration = require base_path('database/migrations/2026_11_02_000001_normalize_user_email_identity.php');

        $this->expectException(\RuntimeException::class);
        $migration->up();
    }

    private function createUser(string $email, string $password = 'password123'): User
    {
        return User::query()->forceCreate([
            'is_admin' => true,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
