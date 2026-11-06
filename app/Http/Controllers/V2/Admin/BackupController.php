<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\BackupService;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    public function list(BackupService $backups)
    {
        return $this->success([
            'files' => $backups->listing(),
            'restore_command' => '停止面板和队列服务，执行 php artisan down；SQLite：php artisan backup:restore <文件名.sqlite.gz> --force；SQL：解压后使用数据库原生导入。验证后执行 php artisan up 并重启服务。',
        ]);
    }

    public function create(BackupService $backups)
    {
        return $this->success(['file' => $backups->create()]);
    }

    public function download(Request $request, BackupService $backups)
    {
        $data = $request->validate(['name' => 'required|string|max:200']);
        try {
            $path = $backups->path($data['name']);
        } catch (\RuntimeException) {
            abort(404);
        }
        return response()->download($path, $data['name'], [
            'Content-Type' => 'application/gzip', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
