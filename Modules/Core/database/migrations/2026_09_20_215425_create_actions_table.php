<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo global de acciones (VER, CREAR, EDITAR, ELIMINAR, EXPORTAR, APROBAR, ESCANEAR, SINCRONIZAR...).
        Schema::create('actions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('icon', 100)->nullable();           // Ícono del botón de la acción (tabler-*)
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->auditColumns();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};
