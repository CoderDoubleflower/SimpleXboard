<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_server_outbound', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32);
            // Encrypted casts contain ciphertext, not a JSON document.
            $table->text('settings');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('v2_server', function (Blueprint $table) {
            $table->foreignId('outbound_id')->nullable()
                ->constrained('v2_server_outbound')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outbound_id');
        });
        Schema::dropIfExists('v2_server_outbound');
    }
};
