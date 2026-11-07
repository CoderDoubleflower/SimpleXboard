<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // All current nodes start at 1x. Round 2 archives are historical only.
        Schema::table('v2_server', function (Blueprint $table) {
            $table->decimal('rate', 5, 2)->default('1.00');
        });
        Schema::table('v2_user', function (Blueprint $table) {
            $table->bigInteger('actual_u')->default(0);
            $table->bigInteger('actual_d')->default(0);
            $table->unsignedTinyInteger('billing_u_remainder')->default(0);
            $table->unsignedTinyInteger('billing_d_remainder')->default(0);
        });
        DB::table('v2_user')->update([
            'actual_u' => DB::raw($this->previousActual('u')),
            'actual_d' => DB::raw($this->previousActual('d')),
        ]);
    }

    public function down(): void
    {
        // Older code derives actual bytes from u/d minus legacy. A discounted
        // byte cannot be represented that way without inventing history.
        // Check before any DDL, and leave all counters untouched if unsafe.
        $cannotRepresent = DB::table('v2_user')->where(function ($query) {
            $query->whereRaw('actual_u <> (' . $this->previousActual('u') . ')')
                ->orWhereRaw('actual_d <> (' . $this->previousActual('d') . ')')
                ->orWhere('billing_u_remainder', '<>', 0)
                ->orWhere('billing_d_remainder', '<>', 0);
        })->exists();
        if ($cannotRepresent) {
            throw new RuntimeException(
                'Cannot roll back fixed traffic multipliers: actual and quota usage differ, or fractional billing remains. '
                . 'No counters or schema were changed. Restore a pre-upgrade backup or keep this version; '
                . 'the previous version cannot preserve these actual byte counts.'
            );
        }
        Schema::table('v2_user', fn (Blueprint $table) => $table->dropColumn([
            'actual_u', 'actual_d', 'billing_u_remainder', 'billing_d_remainder',
        ]));
        Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn('rate'));
    }

    private function previousActual(string $direction): string
    {
        return "CASE WHEN COALESCE({$direction}, 0) > COALESCE(legacy_{$direction}, 0) "
            . "THEN COALESCE({$direction}, 0) - COALESCE(legacy_{$direction}, 0) ELSE 0 END";
    }
};
