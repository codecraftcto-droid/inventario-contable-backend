<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sincronización en tiempo real con los teléfonos:
     *  - inventory_scan_deletions: registros eliminados (desde la web o desde otro teléfono), para
     *    que cada teléfono los quite y su conciliación no siga contando esa cantidad.
     *  - índice (inventory_id, updated_at): "qué cambió desde la última consulta" (altas y ediciones).
     */
    public function up(): void
    {
        Schema::create('inventory_scan_deletions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_uuid')->unique();
            $table->timestamp('deleted_at');

            $table->index(['inventory_id', 'deleted_at']);
        });

        Schema::table('inventory_scans', function (Blueprint $table): void {
            $table->index(['inventory_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_scans', function (Blueprint $table): void {
            $table->dropIndex(['inventory_id', 'updated_at']);
        });
        Schema::dropIfExists('inventory_scan_deletions');
    }
};
