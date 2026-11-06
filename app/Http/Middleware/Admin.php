<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;

class Admin
{
    public function handle($request, Closure $next)
    {
        $path = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        if ($request->route('admin_path') !== null) {
            abort_unless(hash_equals((string) $path, (string) $request->route('admin_path')), 404);
            $request->route()->forgetParameter('admin_path');
        }
        $user = Auth::guard('sanctum')->user();
        if (!$user instanceof User || !$user->is_admin || $user->banned) {
            return response()->json(['message' => '管理员登录已失效'], 403);
        }
        return $next($request);
    }
}
