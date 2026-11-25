<?php

use App\Models\Comprobante;
use App\Services\Ventas\VentaService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Puede registrar empresas nuevas (el dueño del software / soporte)
            $table->boolean('es_superadmin')->default(false)->after('password');
        });

        // Contadores internos (p. ej. número de venta). Nunca retroceden, así un número jamás se reutiliza.
        // Van en su propia tabla y SIN llave foránea a propósito: bloquear la fila de la empresa para
        // numerar provocaba bloqueos mutuos (deadlocks) con las demás operaciones que la leen.
        Schema::create('secuencias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id');
            $table->string('nombre', 40);
            $table->unsignedBigInteger('valor')->default(0);
            $table->unique(['empresa_id', 'nombre']);
        });

        DB::table('empresas')->orderBy('id')->each(function ($empresa) {
            DB::table('secuencias')->insert([
                'empresa_id' => $empresa->id,
                'nombre' => 'venta',
                'valor' => (int) DB::table('ventas')->where('empresa_id', $empresa->id)->max('numero'),
            ]);
        });

        // Comprobantes anteriores a la capa de ventas: se les crea su venta (ya existe la tabla de secuencias)
        $ventas = app(VentaService::class);
        Comprobante::query()->orderByRaw("case when tipo_comprobante in ('01','03') then 0 else 1 end")->orderBy('id')
            ->each(fn ($c) => $ventas->sincronizar($c));

        Schema::table('comprobantes', function (Blueprint $table) {
            // true = se "envió" con el proveedor simulado: no tiene valor tributario
            $table->boolean('simulado')->default(false)->after('estado');
            // Última vez que se avisó a los administradores que está por vencer / vencido
            $table->timestamp('alertado_at')->nullable()->after('enviado_at');
        });

        // Bitácora: quién hizo qué y cuándo. Solo se inserta; nunca se edita ni se borra.
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('usuario_nombre')->nullable();   // copia, por si el usuario se elimina
            $table->string('accion');                        // creó | modificó | eliminó | emitió | ...
            $table->string('modelo_tipo')->nullable();
            $table->unsignedBigInteger('modelo_id')->nullable();
            $table->string('descripcion');
            $table->json('cambios')->nullable();             // {campo: [antes, después]}
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['empresa_id', 'created_at']);
            $table->index(['modelo_tipo', 'modelo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora');
        Schema::table('comprobantes', fn (Blueprint $table) => $table->dropColumn(['simulado', 'alertado_at']));
        Schema::dropIfExists('secuencias');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('es_superadmin'));
    }
};
