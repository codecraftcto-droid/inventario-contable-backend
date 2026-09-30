<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Base contable con el formato definido por R&R: CODIGO PRODUCTO (único y obligatorio),
     * NOMBRE PRODUCTO y UNIDAD MEDIDA (opcionales). El valor se conserva como columna
     * opcional (null): hoy no viene en el Excel, pero se podrá usar más adelante.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->renameColumn('descripcion', 'nombre');
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->string('nombre')->nullable()->change();
            $table->string('unidad_medida', 50)->nullable()->after('nombre');
            $table->decimal('valor', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn('unidad_medida');
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->renameColumn('nombre', 'descripcion');
        });
    }
};
