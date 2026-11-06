<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OPTIONAL, destructive cleanup after a verified backup and retirement of old workers.
 * Excluded from normal migrate. See docs/round-1-handoff.md before running:
 * php artisan migrate --path=database/cleanup --force
 */
return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'v2_gift_card_usage', 'v2_gift_card_code', 'v2_gift_card_template',
            'v2_ticket_message', 'v2_ticket', 'v2_commission_log', 'v2_invite_code',
            'v2_order', 'v2_payment', 'v2_coupon',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        foreach ([
            'v2_user' => ['invite_user_id', 'balance', 'commission_balance', 'commission_rate',
                'commission_type', 'commission_auto_check', 'discount', 'remind_expire', 'remind_traffic'],
            'v2_plan' => ['prices', 'show', 'sell', 'renew', 'capacity_limit', 'content', 'tags'],
        ] as $table => $columns) {
            $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
            if ($existing) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($existing));
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('商业数据已被永久清理；回退必须恢复清理前的数据库备份。');
    }
};
