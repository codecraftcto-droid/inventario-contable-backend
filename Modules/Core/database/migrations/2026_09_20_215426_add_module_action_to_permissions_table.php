<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un permiso = módulo + acción (único por par). Se reutiliza la tabla de
     * spatie/laravel-permission y su "name" se arma como MODULO:ACCION.
     */
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            $table->foreignId('module_id')->nullable()->after('guard_name')->constrained('modules')->cascadeOnDelete();
            $table->foreignId('action_id')->nullable()->after('module_id')->constrained('actions')->cascadeOnDelete();
            $table->string('description')->nullable()->after('action_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['module_id', 'action_id', 'guard_name']);
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            $table->dropUnique(['module_id', 'action_id', 'guard_name']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('module_id');
            $table->dropConstrainedForeignId('action_id');
            $table->dropColumn('description');
        });
    }
};
