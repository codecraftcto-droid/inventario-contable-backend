<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Empresas cliente. Cada una tiene sus inventarios y sus propios usuarios (users.company_id).
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('document_type')->nullable();
            $table->string('document_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            // Usuarios que la empresa puede tener según su contrato (null = sin límite).
            $table->unsignedInteger('max_users')->nullable();
            $table->boolean('is_active')->default(true);
            $table->auditColumns();

            $table->index('name');   // Listado ordenado/buscado por razón social
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
