<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles (tabla de spatie). La relación rol-permiso es role_has_permissions y
     * usuario-rol es model_has_roles (ambas de spatie).
     *
     * Futuro (unidades orgánicas): cuando se agreguen, el rol por unidad se puede
     * modelar con una tabla propia user_role_assignments (user_id, role_id,
     * organic_unit_id) sin tocar esta tabla ni la de permisos.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('description')->nullable()->after('guard_name');
            $table->boolean('is_system')->default(false)->after('description'); // Rol de sistema: no se elimina
            $table->boolean('is_active')->default(true)->after('is_system');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['description', 'is_system', 'is_active']);
        });
    }
};
