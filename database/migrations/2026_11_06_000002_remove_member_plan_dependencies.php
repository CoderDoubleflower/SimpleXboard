<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Run with the panel and workers stopped, after a database backup.
 * Archives retain original schemas/data; they are not runtime feature tables.
 */
return new class extends Migration {
    private const RETIRED_TABLES = [
        'v2_plan', 'v2_server_group', 'v2_plugins', 'v2_knowledge', 'v2_notice',
        'v2_mail_log', 'v2_mail_templates', 'v2_order', 'v2_payment', 'v2_coupon',
        'v2_commission_log', 'v2_invite_code', 'v2_ticket', 'v2_ticket_message',
        'v2_gift_card_template', 'v2_gift_card_code', 'v2_gift_card_usage',
    ];
    private const USER_RETIRED_COLUMNS = [
        'plan_id', 'group_id', 'is_staff', 'telegram_id', 'next_reset_at',
        'invite_user_id', 'balance', 'discount', 'commission_type', 'commission_rate',
        'commission_balance', 'commission_auto_check', 'remind_expire', 'remind_traffic',
    ];
    private const SERVER_RETIRED_COLUMNS = ['group_ids', 'rate', 'rate_time_enable', 'rate_time_ranges'];

    public function up(): void
    {
        if (!Schema::hasTable('v2_round2_archive_metadata')) {
            Schema::create('v2_round2_archive_metadata', function (Blueprint $table) {
                $table->string('name')->primary();
                $table->longText('payload');
            });
            $this->putMeta('started_at', time());
            $this->putMeta('user_columns', Schema::getColumnListing('v2_user'));
            $this->putMeta('server_columns', Schema::getColumnListing('v2_server'));
            $this->putMeta('user_indexes', Schema::getIndexes('v2_user'));
            $this->putMeta('server_indexes', Schema::getIndexes('v2_server'));
        }
        $this->snapshot('v2_user', 'v2_round2_archive_user');
        $this->snapshot('v2_server', 'v2_round2_archive_server');
        $this->snapshot('personal_access_tokens', 'v2_round2_archive_tokens');

        if (!Schema::hasColumn('v2_user', 'name')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->string('name', 120)->nullable();
                $table->string('access_mode', 12)->default('all');
                $table->bigInteger('legacy_u')->default(0);
                $table->bigInteger('legacy_d')->default(0);
            });
        }
        Schema::table('v2_user', function (Blueprint $table) {
            $email = $table->string('email', 64)->nullable();
            if (DB::getDriverName() === 'mysql') $email->charset('utf8mb4')->collation('utf8mb4_bin');
            if (DB::getDriverName() === 'pgsql') $email->collation('C');
            $email->change();
            $table->string('password', 64)->nullable()->change();
            $table->bigInteger('transfer_enable')->nullable()->default(null)->change();
            $table->bigInteger('expired_at')->nullable()->default(null)->change();
        });
        if (!Schema::hasTable('v2_user_server')) {
            Schema::create('v2_user_server', function (Blueprint $table) {
                $table->integer('user_id');
                $table->unsignedBigInteger('server_id');
                $table->primary(['user_id', 'server_id']);
                $table->foreign('user_id')->references('id')->on('v2_user')->cascadeOnDelete();
                $table->foreign('server_id')->references('id')->on('v2_server')->cascadeOnDelete();
            });
        }

        // Snapshot group membership without considering show, quota or momentary expiry.
        // Every old member keeps only the original set, including members with no group.
        $groupNodes = [];
        DB::table('v2_round2_archive_server')->orderBy('id')->chunkById(500, function ($rows) use (&$groupNodes) {
            foreach ($rows as $row) {
                $server = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $groups = is_string($server['group_ids'] ?? null)
                    ? json_decode($server['group_ids'], true) : ($server['group_ids'] ?? []);
                foreach (is_array($groups) ? $groups : [] as $group) {
                    if (is_numeric($group)) $groupNodes[(string) (int) $group][] = (int) $row->id;
                }
            }
        });
        DB::table('v2_round2_archive_user')->orderBy('id')->chunkById(500, function ($rows) use ($groupNodes) {
            foreach ($rows as $row) {
                $old = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                DB::table('v2_user')->where('id', $row->id)->update([
                    'access_mode' => 'restricted',
                    // A legacy null quota was not unlimited. Do not activate it now.
                    'transfer_enable' => $old['transfer_enable'] ?? 0,
                    'legacy_u' => (int) ($old['u'] ?? 0),
                    'legacy_d' => (int) ($old['d'] ?? 0),
                ]);
                DB::table('v2_user_server')->where('user_id', $row->id)->delete();
                foreach ($groupNodes[(string) ($old['group_id'] ?? '')] ?? [] as $serverId) {
                    DB::table('v2_user_server')->insertOrIgnore(['user_id' => $row->id, 'server_id' => $serverId]);
                }
            }
        });
        DB::table('v2_user')->whereNull('u')->update(['u' => 0]);
        DB::table('v2_user')->whereNull('d')->update(['d' => 0]);
        DB::table('v2_user')->where('is_admin', false)->update([
            'password' => null, 'password_algo' => null, 'password_salt' => null,
        ]);
        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')
            ->whereIn('tokenable_id', DB::table('v2_user')->where('is_admin', false)->select('id'))->delete();

        // Standalone legacy nodes did not check enabled; preserve their unset default.
        // Machine assignment already required enabled=true, so an unset machine node stays off.
        DB::table('v2_server')->whereNull('enabled')->whereNull('machine_id')->update(['enabled' => true]);
        DB::table('v2_server')->whereNull('enabled')->whereNotNull('machine_id')->update(['enabled' => false]);
        Schema::table('v2_server', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->change();
            $table->boolean('show')->default(true)->change();
        });
        $this->dropRetiredColumns('v2_user', self::USER_RETIRED_COLUMNS);
        $this->dropRetiredColumns('v2_server', self::SERVER_RETIRED_COLUMNS);

        foreach (self::RETIRED_TABLES as $table) $this->archiveTable($table);
        $this->archiveTable('v2_stat_user');
        if (!Schema::hasTable('v2_stat_user')) {
            Schema::create('v2_stat_user', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('user_id');
                $table->bigInteger('u')->default(0);
                $table->bigInteger('d')->default(0);
                $table->char('record_type', 2);
                $table->integer('record_at');
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique(['user_id', 'record_at', 'record_type'], 'round2_user_period_unique');
                $table->index('record_at', 'round2_user_record_at_index');
            });
        }
        $this->archiveTable('v2_stat');
        if (!Schema::hasTable('v2_stat')) {
            Schema::create('v2_stat', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('record_at');
                $table->char('record_type', 1);
                $table->integer('register_count')->default(0);
                $table->bigInteger('transfer_used_total')->default(0);
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique('record_at', 'round2_stat_record_at_unique');
            });
            DB::table('v2_round2_archive_stat')->orderBy('id')->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    // Node traffic totals were already actual bytes before this migration.
                    DB::table('v2_stat')->updateOrInsert(['record_at' => $row->record_at], [
                        'record_type' => $row->record_type, 'register_count' => $row->register_count,
                        'transfer_used_total' => $row->transfer_used_total,
                        'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
                    ]);
                }
            });
        }
        $this->putMeta('completed_at', time());
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_round2_archive_metadata')) {
            throw new RuntimeException('Round 2 archives are required for rollback. Restore the pre-upgrade database backup.');
        }
        // Keep post-upgrade usage and identities available for forensic recovery.
        $this->snapshot('v2_user', 'v2_round2_rollback_user');
        $this->snapshot('v2_server', 'v2_round2_rollback_server');
        $this->snapshot('v2_stat_user', 'v2_round2_rollback_stat_user');
        $this->snapshot('v2_stat', 'v2_round2_rollback_stat');
        $this->restoreUserColumns();
        $this->restoreServerColumns();

        DB::table('v2_round2_archive_user')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $old = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $restore = array_intersect_key($old, array_flip(self::USER_RETIRED_COLUMNS));
                $current = DB::table('v2_user')->where('id', $row->id)->first();
                if (!$current) continue; // Do not resurrect a member deleted after upgrade.
                if (!$current->is_admin && !$old['is_admin']) {
                    foreach (['password', 'password_algo', 'password_salt'] as $field) $restore[$field] = $old[$field] ?? null;
                }
                if ($current->transfer_enable === null) $restore['transfer_enable'] = $old['transfer_enable'] ?? 0;
                if ($current->transfer_enable !== null && (int) $current->transfer_enable === 0 && $old['transfer_enable'] === null) {
                    $restore['transfer_enable'] = null;
                }
                DB::table('v2_user')->where('id', $row->id)->update($restore);
            }
        });
        // New subscription-only members have no legacy plan: fail closed on old code.
        DB::table('v2_user')->where('is_admin', false)
            ->whereNotIn('id', DB::table('v2_round2_archive_user')->select('id'))
            ->update(['banned' => true, 'transfer_enable' => 0]);
        DB::table('v2_round2_archive_server')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $old = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $restore = array_intersect_key($old, array_flip(self::SERVER_RETIRED_COLUMNS));
                $restore['enabled'] = $old['enabled'] ?? null;
                DB::table('v2_server')->where('id', $row->id)->update($restore);
            }
        });
        foreach (self::RETIRED_TABLES as $table) $this->restoreTable($table);
        foreach (['v2_stat_user', 'v2_stat'] as $table) {
            Schema::dropIfExists($table);
            $this->restoreTable($table);
        }
        $this->restoreIndexes('v2_user', $this->getMeta('user_indexes'));
        $this->restoreIndexes('v2_server', $this->getMeta('server_indexes'));
        // Tokens and password archives are retained, but revoked sessions stay revoked.
        // Code rollback requires a fresh login rather than reviving pre-upgrade sessions.
        foreach (['v2_round2_archive_user', 'v2_round2_archive_server', 'v2_round2_archive_tokens', 'v2_round2_archive_metadata'] as $table) {
            if ($table === 'v2_round2_archive_tokens') continue;
            Schema::dropIfExists($table);
        }
        // New additive columns/pivot remain harmless to Round 1 and retain new records.
    }

    private function snapshot(string $source, string $archive): void
    {
        if (Schema::hasTable($archive) || !Schema::hasTable($source)) return;
        Schema::create($archive, function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->longText('payload');
        });
        DB::table($source)->orderBy('id')->chunkById(500, function ($rows) use ($archive) {
            foreach ($rows as $row) DB::table($archive)->insert([
                'id' => $row->id, 'payload' => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    private function archiveTable(string $table): void
    {
        $archive = 'v2_round2_archive_' . substr($table, 3);
        if (Schema::hasTable($table) && !Schema::hasTable($archive)) Schema::rename($table, $archive);
    }

    private function restoreTable(string $table): void
    {
        $archive = 'v2_round2_archive_' . substr($table, 3);
        if (Schema::hasTable($archive) && !Schema::hasTable($table)) Schema::rename($archive, $table);
    }

    private function dropRetiredColumns(string $table, array $columns): void
    {
        $columns = array_values(array_intersect($columns, Schema::getColumnListing($table)));
        if (!$columns) return;
        foreach (Schema::getIndexes($table) as $index) {
            if (!array_intersect($columns, $index['columns']) || $index['primary']) continue;
            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $index['unique'] ? $blueprint->dropUnique($index['name']) : $blueprint->dropIndex($index['name']);
            });
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($columns));
    }

    private function restoreUserColumns(): void
    {
        $original = $this->getMeta('user_columns');
        $missing = array_diff(array_intersect(self::USER_RETIRED_COLUMNS, $original), Schema::getColumnListing('v2_user'));
        Schema::table('v2_user', function (Blueprint $table) use ($missing) {
            foreach ($missing as $column) {
                if ($column === 'telegram_id') $table->bigInteger($column)->nullable();
                elseif ($column === 'is_staff') $table->boolean($column)->default(false);
                elseif (in_array($column, ['balance', 'commission_balance', 'commission_type'], true)) $table->integer($column)->default(0);
                elseif (in_array($column, ['remind_expire', 'remind_traffic'], true)) $table->tinyInteger($column)->nullable()->default(1);
                else $table->integer($column)->nullable();
            }
        });
    }

    private function restoreServerColumns(): void
    {
        $missing = array_diff(self::SERVER_RETIRED_COLUMNS, Schema::getColumnListing('v2_server'));
        Schema::table('v2_server', function (Blueprint $table) use ($missing) {
            foreach ($missing as $column) {
                if ($column === 'rate') $table->decimal('rate', 8, 2)->default(1);
                elseif ($column === 'rate_time_enable') $table->boolean('rate_time_enable')->default(false);
                else $table->json($column)->nullable();
            }
            $table->boolean('enabled')->nullable()->default(true)->change();
        });
    }

    private function restoreIndexes(string $table, array $indexes): void
    {
        $existing = array_column(Schema::getIndexes($table), 'name');
        foreach ($indexes as $index) {
            if ($index['primary'] || in_array($index['name'], $existing, true)) continue;
            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $index['unique'] ? $blueprint->unique($index['columns'], $index['name']) : $blueprint->index($index['columns'], $index['name']);
            });
        }
    }

    private function putMeta(string $name, mixed $value): void
    {
        DB::table('v2_round2_archive_metadata')->updateOrInsert(['name' => $name], ['payload' => json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    private function getMeta(string $name): mixed
    {
        return json_decode(DB::table('v2_round2_archive_metadata')->where('name', $name)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    }
};
