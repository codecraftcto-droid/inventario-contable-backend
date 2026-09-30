<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sesiones de usuario = refresh tokens emitidos por dispositivo. Permite
     * listar sesiones activas y revocarlas remotamente (ej. celular perdido).
     * Solo se guarda el hash SHA-256 del refresh token, nunca el token en claro.
     */
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('refresh_token_hash', 64)->unique();
            $table->string('platform', 10);                   // WEB | MOVIL
            $table->string('device_id', 100)->nullable();
            $table->string('device_name', 150)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 50)->nullable();   // UserSession::REVOKED_* (LOGOUT, ADMIN, DISPOSITIVO, CAMBIO_CLAVE...)
            $table->timestamps();

            $table->index(['user_id', 'revoked_at', 'expires_at']);
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
