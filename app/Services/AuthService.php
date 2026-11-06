<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public function __construct(private User $user)
    {
    }

    public function generateAuthData(): array
    {
        if (!$this->user->is_admin || $this->user->banned) {
            throw new AuthorizationException('只有管理员可以建立登录会话');
        }
        $token = $this->user->createToken('admin-' . Str::random(12), ['admin'], now()->addDays(30));
        return [
            'auth_data' => 'Bearer ' . $token->plainTextToken,
            'is_admin' => true,
        ];
    }

    public function getSessions(): array
    {
        return $this->user->tokens()->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])->toArray();
    }

    public function removeSession(string $sessionId): bool
    {
        $this->user->tokens()->where('id', $sessionId)->delete();
        return true;
    }

    public function removeAllSessions(): bool
    {
        $this->user->tokens()->delete();
        return true;
    }

    public static function findUserByBearerToken(string $bearerToken): ?User
    {
        $accessToken = PersonalAccessToken::findToken(preg_replace('/^Bearer\s+/i', '', $bearerToken));
        if (!$accessToken || ($accessToken->expires_at && $accessToken->expires_at->isPast())) {
            return null;
        }
        $user = $accessToken->tokenable;
        return $user instanceof User && $user->is_admin && !$user->banned ? $user : null;
    }
}
