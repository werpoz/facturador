<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('controla_stock')->default(false)->after('tipo_afectacion_igv'); // los servicios no llevan stock
            $table->decimal('stock', 14, 4)->default(0)->after('controla_stock');
            $table->decimal('stock_minimo', 14, 4)->nullable()->after('stock');              // aviso de "por reponer"
        });

        Schema::table('empresas', function (Blueprint $table) {
            // false = se puede vender aunque el sistema diga que no hay (queda en negativo y avisa)
            $table->boolean('bloquear_venta_sin_stock')->default(false)->after('regimen_tributario');
        });

        // Kardex: cada entrada o salida de mercadería. El stock de un producto es SIEMPRE la suma de sus movimientos.
        Schema::create('movimientos_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained()->cascadeOnDelete();
            $table->string('tipo');                              // inicial | ingreso | venta | devolucion | ajuste | merma | reversion
            $table->decimal('cantidad', 14, 4);                  // positiva = entra, negativa = sale
            $table->decimal('stock_resultante', 14, 4);          // cuánto quedó después del movimiento
            $table->foreignId('comprobante_id')->nullable()->constrained()->nullOnDelete();
            $table->string('motivo')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['empresa_id', 'producto_id', 'id']);
            $table->index('comprobante_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_stock');
        Schema::table('empresas', fn (Blueprint $table) => $table->dropColumn('bloquear_venta_sin_stock'));
        Schema::table('productos', fn (Blueprint $table) => $table->dropColumn(['controla_stock', 'stock', 'stock_minimo']));
    }
};
