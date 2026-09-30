<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Activos de la base de datos contable cargada en un inventario.
     * El código es único dentro de cada inventario (dos empresas pueden repetir códigos).
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_id')->constrained('inventories')->cascadeOnDelete();
            $table->string('codigo');
            $table->string('descripcion');
            $table->decimal('valor', 12, 2);
            $table->auditColumns();

            $table->unique(['inventory_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
