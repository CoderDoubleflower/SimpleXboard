<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SecurityController extends Controller
{
    public function me(Request $request)
    {
        return $this->success($request->user()->only(['id', 'name', 'email', 'last_login_at']));
    }

    public function sessions(Request $request)
    {
        $currentId = $request->user()->currentAccessToken()?->getKey();
        return $this->success($request->user()->tokens()->orderByDesc('id')
            ->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at'])
            ->map(fn ($token) => $token->toArray() + ['is_current' => $token->id === $currentId]));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return $this->success(true);
    }

    public function revokeSessions(Request $request)
    {
        $request->user()->tokens()->delete();
        return $this->success(true);
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'old_password' => 'required|string|max:256',
            'new_password' => 'required|string|min:12|max:256|confirmed',
        ]);
        $user = $request->user();
        if (!Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $data['old_password'], $user->password)) {
            throw ValidationException::withMessages(['old_password' => '当前密码不正确']);
        }
        DB::transaction(function () use ($user, $data) {
            $user->password = Hash::make($data['new_password']);
            $user->password_algo = null;
            $user->password_salt = null;
            $user->save();
            $user->tokens()->delete();
        });
        return $this->success(true);
    }
}
