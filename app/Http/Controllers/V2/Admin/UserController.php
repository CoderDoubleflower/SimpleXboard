<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserCreate;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Admin\UserUpdate;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function fetch(UserFetch $request)
    {
        $query = $this->memberQuery($request)->with('servers:id');
        $users = $query->orderByDesc('id')->paginate($request->integer('pageSize', 20), ['*'], 'page', $request->integer('current', 1));
        $users->getCollection()->transform(fn (User $user) => self::transformUserData($user));
        return $this->paginate($users);
    }

    private function memberQuery(Request $request): Builder
    {
        $query = User::where('is_admin', false);
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(fn (Builder $q) => $q->where('name', 'like', '%' . $term . '%')
                ->orWhere('email', 'like', '%' . User::normalizeEmail($term) . '%'));
        }
        if ($request->has('banned') && $request->input('banned') !== null) {
            $query->where('banned', $request->boolean('banned'));
        }
        return $query;
    }

    public static function transformUserData(User $user): array
    {
        $data = $user->only([
            'id', 'name', 'email', 'token', 'uuid', 'banned', 'transfer_enable', 'u', 'd',
            'legacy_u', 'legacy_d', 'speed_limit', 'device_limit', 'expired_at', 'access_mode',
            'remarks', 'created_at', 'updated_at', 'last_reset_at', 'reset_count',
        ]);
        $data['subscribe_url'] = Helper::getSubscribeUrl($user->token);
        $data['total_used'] = $user->getTotalUsedTraffic();
        $data['remaining_traffic'] = $user->getRemainingTraffic();
        $data['actual_u'] = $user->getActualUpload();
        $data['actual_d'] = $user->getActualDownload();
        $data['eligible'] = $user->isAvailable();
        $data['server_ids'] = $user->servers->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        return $data;
    }

    public function getUserInfoById(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        return $this->success(self::transformUserData(User::where('is_admin', false)->findOrFail($request->integer('id'))));
    }

    public function create(UserCreate $request)
    {
        $member = DB::transaction(function () use ($request) {
            $service = app(UserService::class);
            $member = $service->createUser($request->validated());
            $member->save();
            $service->syncServerAccess($member, $request->input('server_ids', []));
            return $member;
        });
        return $this->success(self::transformUserData($member->refresh()));
    }

    public function update(UserUpdate $request)
    {
        $data = $request->validated();
        $member = DB::transaction(function () use ($data) {
            $member = User::where('is_admin', false)->lockForUpdate()->findOrFail($data['id']);
            $hasServerIds = array_key_exists('server_ids', $data);
            $serverIds = $data['server_ids'] ?? $member->servers()->pluck('v2_server.id')->all();
            unset($data['id'], $data['server_ids']);
            $member->fill($data);
            if (blank($member->name) && blank($member->email)) {
                throw ValidationException::withMessages(['name' => '请保留成员名称或邮箱']);
            }
            if ($member->access_mode !== 'restricted' && !empty($serverIds) && $hasServerIds) {
                throw ValidationException::withMessages(['server_ids' => '指定节点时必须使用restricted访问模式']);
            }
            $member->save();
            app(UserService::class)->syncServerAccess($member, $serverIds);
            if ($member->banned) $member->tokens()->delete();
            return $member;
        });
        return $this->success(self::transformUserData($member->refresh()));
    }

    public function ban(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer', 'banned' => 'required|boolean']);
        $member = DB::transaction(function () use ($data) {
            $member = User::where('is_admin', false)->lockForUpdate()->findOrFail($data['id']);
            $member->update(['banned' => $data['banned']]);
            if ($member->banned) $member->tokens()->delete();
            return $member;
        });
        return $this->success(self::transformUserData($member->refresh()));
    }

    public function resetSecret(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $member = DB::transaction(function () use ($request) {
            $member = User::where('is_admin', false)->lockForUpdate()->findOrFail($request->integer('id'));
            $member->update(['token' => Helper::guid(), 'uuid' => Helper::guid(true)]);
            return $member;
        });
        return $this->success(self::transformUserData($member->refresh()));
    }

    public function destroy(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        DB::transaction(function () use ($request) {
            $member = User::where('is_admin', false)->lockForUpdate()->findOrFail($request->integer('id'));
            $member->tokens()->delete();
            $member->trafficResetLogs()->delete();
            $member->stat()->delete();
            $member->servers()->detach();
            $member->delete();
        });
        return $this->success(true);
    }

    public function dumpCSV(Request $request)
    {
        $data = $request->validate([
            'scope' => 'sometimes|in:all,selected',
            'user_ids' => 'required_if:scope,selected|array|min:1',
            'user_ids.*' => 'integer|distinct',
            'search' => 'nullable|string|max:120', 'banned' => 'nullable|boolean',
            'filter' => 'prohibited',
        ]);
        $query = $this->memberQuery($request)->with('servers:id')->orderBy('id');
        if (($data['scope'] ?? 'all') === 'selected') $query->whereIn('id', $data['user_ids']);
        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'name', 'email', 'banned', 'limit_bytes', 'quota_used_bytes', 'legacy_quota_bytes', 'actual_upload_bytes', 'actual_download_bytes', 'expired_at', 'access_mode', 'server_ids', 'subscribe_url'], escape: '');
            $query->chunkById(500, function ($members) use ($out) {
                foreach ($members as $member) {
                    $safe = fn ($value) => preg_match('/^[=+@\-\t\r]/u', (string) $value) ? "'" . $value : $value;
                    fputcsv($out, [
                        $member->id, $safe($member->name), $safe($member->email), (int) $member->banned,
                        $member->transfer_enable ?? 'unlimited', $member->getTotalUsedTraffic(),
                        (int) $member->legacy_u + (int) $member->legacy_d,
                        $member->getActualUpload(), $member->getActualDownload(), $member->expired_at,
                        $member->access_mode, implode('|', $member->servers->modelKeys()), Helper::getSubscribeUrl($member->token),
                    ], escape: '');
                }
            });
            fclose($out);
        }, 'members-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
