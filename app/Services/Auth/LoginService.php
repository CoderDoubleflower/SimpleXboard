<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

/** Password authentication belongs exclusively to the administrator. */
class LoginService
{
    public function login(string $email, string $password): array
    {
        $email = User::normalizeEmail($email);
        $key = CacheKey::get('PASSWORD_ERROR_LIMIT', $email);
        $limitEnabled = (bool) admin_setting('password_limit_enable', true);
        $attempts = (int) Cache::get($key, 0);
        if ($limitEnabled && $attempts >= (int) admin_setting('password_limit_count', 5)) {
            return [false, [429, '登录尝试过多，请稍后再试']];
        }

        $user = User::byEmail($email)->where('is_admin', true)->first();
        if (!$user || $user->banned || !$user->password || !Helper::multiPasswordVerify(
            $user->password_algo, $user->password_salt, $password, $user->password
        )) {
            if ($limitEnabled) {
                Cache::put($key, $attempts + 1, 60 * (int) admin_setting('password_limit_expire', 15));
            }
            return [false, [400, '邮箱或密码错误']];
        }

        Cache::forget($key);
        $user->last_login_at = time();
        $user->save();
        return [true, $user];
    }
}
