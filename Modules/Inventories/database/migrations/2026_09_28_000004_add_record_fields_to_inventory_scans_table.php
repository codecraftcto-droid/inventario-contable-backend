<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada lectura pasa a ser un REGISTRO de la toma (como el APK "App Suministros"):
     * cantidad, estado de conservación, sede (ubicación), pocket, detalle del bien y
     * observaciones. El mismo código puede registrarse varias veces y las cantidades se suman.
     */
    public function up(): void
    {
        Schema::table('inventory_scans', function (Blueprint $table): void {
            $table->decimal('quantity', 12, 2)->default(1)->after('outcome');
            $table->string('condition', 10)->nullable()->after('quantity');            // App\Enums\ScanCondition
            $table->foreignId('site_id')->nullable()->after('condition')->constrained('sites')->nullOnDelete();
            $table->string('pocket', 50)->nullable()->after('location');
            $table->string('detail')->nullable()->after('pocket');                     // Detalle del bien
            $table->string('observations', 500)->nullable()->after('detail');
            // Hora de la última edición EN EL EQUIPO: si llegan dos ediciones (p. ej. una
            // atrasada por falta de señal) solo se aplica la más reciente.
            $table->timestamp('client_updated_at')->nullable()->after('scanned_at');

            $table->index(['inventory_id', 'site_id']);                               // Resultado por sede
        });

        Schema::create('inventory_scan_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_scan_id')->constrained('inventory_scans')->cascadeOnDelete();
            $table->uuid('client_uuid')->unique();   // Reintentar la subida no duplica la foto
            $table->string('disk', 30);
            $table->string('path');
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_scan_photos');

        Schema::table('inventory_scans', function (Blueprint $table): void {
            $table->dropIndex(['inventory_id', 'site_id']);
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn(['quantity', 'condition', 'pocket', 'detail', 'observations', 'client_updated_at']);
        });
    }
};
