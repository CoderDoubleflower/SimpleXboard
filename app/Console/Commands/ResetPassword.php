<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetPassword extends Command
{
    protected $signature = 'reset:password {email} {password?}';
    protected $description = '重置管理员密码并撤销所有登录会话';

    public function handle(): int
    {
        $user = User::byEmail($this->argument('email'))->where('is_admin', true)->first();
        if (!$user) {
            $this->error('管理员不存在');
            return self::FAILURE;
        }
        $password = $this->argument('password') ?? $this->secret('新密码（至少12位）');
        if (!is_string($password) || strlen($password) < 12) {
            $this->error('新密码至少12位');
            return self::FAILURE;
        }
        DB::transaction(function () use ($user, $password) {
            $user->password = Hash::make($password);
            $user->password_algo = null;
            $user->password_salt = null;
            $user->save();
            $user->tokens()->delete();
        });
        $this->info('管理员密码已更新，原登录会话已撤销。');
        return self::SUCCESS;
    }
}
