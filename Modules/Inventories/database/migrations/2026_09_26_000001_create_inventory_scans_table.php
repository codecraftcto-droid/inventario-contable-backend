<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lecturas de la toma de inventario (web y app). Pensada para muchos equipos
     * enviando a la vez y con mala señal:
     *  - client_uuid único: si el equipo reintenta un envío (ej. se cortó la red
     *    después de que el servidor lo guardó) no se duplica nada.
     *  - scanned_at = hora de la lectura en el equipo; created_at = cuándo llegó.
     */
    public function up(): void
    {
        Schema::create('inventory_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_id')->constrained('inventories')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->uuid('client_uuid')->unique();
            $table->string('code', 100);
            $table->string('outcome', 12);                       // App\Enums\ScanOutcome
            $table->string('location', 150)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 100)->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['inventory_id', 'id']);                // Descarga por cursor
            $table->index(['inventory_id', 'asset_id', 'outcome']);
            $table->index(['inventory_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_scans');
    }
};
