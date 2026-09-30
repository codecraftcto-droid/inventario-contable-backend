<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1) Ciclo de la toma: Pendiente → (Iniciar) → En proceso ⇄ Pausado → (Finalizar) → Finalizado.
     *    Solo se registra con el inventario en proceso; el historial guarda quién y cuándo.
     * 2) Pockets: zonas / tandas de conteo del inventario (opcionalmente dentro de una sede),
     *    asignadas a uno o varios inventariadores. Cada registro queda enlazado a su pocket.
     */
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->after('status');
            $table->foreignId('started_by')->nullable()->after('started_at')->constrained('users')->nullOnDelete();
            $table->timestamp('paused_at')->nullable()->after('started_by');   // Inicio de la pausa actual
        });

        Schema::create('inventory_status_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->string('action', 15);          // INICIAR, PAUSAR, REANUDAR, FINALIZAR
            $table->string('from_status', 15);
            $table->string('to_status', 15);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['inventory_id', 'created_at']);
        });

        Schema::create('inventory_pockets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('code', 30);
            $table->string('name')->nullable();
            $table->string('status', 12)->default('PENDIENTE');   // PENDIENTE, EN_CONTEO, TERMINADO
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('finished_by')->nullable()->constrained('users')->nullOnDelete();
            $table->auditColumns();

            $table->unique(['inventory_id', 'code']);
        });

        Schema::create('inventory_pocket_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_pocket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['inventory_pocket_id', 'user_id']);
            $table->index(['user_id', 'inventory_pocket_id']);
        });

        Schema::table('inventory_scans', function (Blueprint $table): void {
            $table->foreignId('pocket_id')->nullable()->after('site_id')->constrained('inventory_pockets')->nullOnDelete();
            $table->index(['inventory_id', 'pocket_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_scans', function (Blueprint $table): void {
            $table->dropIndex(['inventory_id', 'pocket_id']);
            $table->dropConstrainedForeignId('pocket_id');
        });
        Schema::dropIfExists('inventory_pocket_user');
        Schema::dropIfExists('inventory_pockets');
        Schema::dropIfExists('inventory_status_logs');
        Schema::table('inventories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('started_by');
            $table->dropColumn(['started_at', 'paused_at']);
        });
    }
};
