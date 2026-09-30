<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Módulos del sistema en árbol (sin límite de niveles vía parent_id).
        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('modules')->restrictOnDelete();
            $table->string('code', 30)->unique();           // Ej. INV_TOM (se usa en el permiso INV_TOM:ESCANEAR)
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('icon', 100)->nullable();
            $table->string('path')->nullable();             // Ruta del front; null en módulos agrupadores
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('platform', 10)->default('WEB');  // WEB | MOVIL | AMBOS (App\Enums\Platform)
            $table->boolean('is_menu')->default(true);      // false = opción dentro de su módulo padre (botón), no va en el menú lateral
            $table->boolean('is_active')->default(true);
            $table->boolean('is_locked')->default(false);    // Módulos base del sistema: no se eliminan
            $table->auditColumns();

            $table->index(['parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        // La FK a sí misma (parent_id) impediría borrar la tabla si tiene submódulos.
        Schema::withoutForeignKeyConstraints(fn () => Schema::dropIfExists('modules'));
    }
};
