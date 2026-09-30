<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de accesos: LOGIN_OK, LOGIN_FALLIDO, LOGOUT, BLOQUEO, CAMBIO_CLAVE.
     * Es de solo inserción, por eso no lleva updated_at ni updated_by.
     */
    public function up(): void
    {
        Schema::create('access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_session_id')->nullable()->constrained('user_sessions')->nullOnDelete();
            $table->string('login', 150)->nullable();          // Usuario/correo digitado (útil en intentos fallidos)
            $table->string('event', 20);                       // App\Enums\AccessEvent
            $table->string('platform', 10)->nullable();
            $table->string('device_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};
