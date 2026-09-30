<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CANTIDAD de la base contable: lo que debería haber de cada producto. La toma se
     * concilia contra ella (conciliado, faltante, sobrante, diferencia positiva / negativa).
     * Vacía = 1 (el producto figura al menos una vez).
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->decimal('cantidad', 12, 2)->nullable()->after('unidad_medida');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn('cantidad');
        });
    }
};
