<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sedes (locales) de cada empresa cliente. Por ahora solo su registro; más adelante
        // se podrán asociar a los inventarios y a las lecturas de la toma.
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->auditColumns();

            $table->unique(['company_id', 'code']);   // El código se repite entre empresas, no dentro de una
            $table->index(['company_id', 'name']);    // Listado de sedes de una empresa ordenado por nombre
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
