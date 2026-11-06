<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_plan', 'is_internal')) {
            Schema::table('v2_plan', function (Blueprint $table) {
                $table->boolean('is_internal')->default(false)->index();
            });
        }

        // Do not change existing members, credentials or node/group assignments.
        DB::transaction(function () {
            if (DB::table('v2_plan')->where('is_internal', true)->exists()) {
                return;
            }

            $now = time();
            $groupId = DB::table('v2_server_group')->insertGetId([
                'name' => '内部默认成员组',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('v2_plan')->insert([
                'name' => '内部默认成员配置',
                'group_id' => $groupId,
                'transfer_enable' => 1024,
                'speed_limit' => null,
                'device_limit' => null,
                'reset_traffic_method' => 0,
                'show' => false,
                'sell' => false,
                'renew' => false,
                'is_internal' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        // Existing plugin settings remain archived; retired payment code is never booted.
        DB::table('v2_plugins')->where('type', 'payment')->update(['is_enabled' => false]);
    }

    public function down(): void
    {
        // Deliberately retain this additive schema and its marker on rollback.
        // Old code ignores is_internal; deleting it would orphan the default
        // configuration on a later upgrade and change lifetime members' resets.
        // Payment plugins are not automatically re-enabled by a code rollback.
    }
};
