<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personal interno asignado a cada inventario. Quien no tiene el permiso INV:VER_TODOS
        // (p. ej. un inventariador) solo ve y opera, en la web y en la app, los inventarios asignados.
        Schema::create('inventory_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['inventory_id', 'user_id']);
            $table->index(['user_id', 'inventory_id']);   // "Mis inventarios" (filtro del listado y de la app)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_assignments');
    }
};
