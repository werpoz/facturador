<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tablas con registros financieros o de auditoría: no deben desaparecer al borrar su empresa */
    private const DE_LA_EMPRESA = ['comprobantes', 'ventas', 'pagos', 'movimientos_stock', 'bitacora'];

    public function up(): void
    {
        // ── Operaciones gratuitas ──
        Schema::table('comprobante_items', function (Blueprint $table) {
            // Valor unitario de mercado (sin IGV) de lo que se regala. En un ítem gratuito el valor
            // unitario es 0 y este es el dato con el que se calcula la base y el IGV que se informan.
            $table->decimal('valor_referencial', 22, 10)->nullable()->after('valor_unitario');
        });
        Schema::table('comprobantes', function (Blueprint $table) {
            // IGV de las operaciones gratuitas: se informa aparte y NO se suma al IGV ni al total a pagar
            $table->decimal('igv_gratuitas', 14, 2)->default(0)->after('op_gratuitas');
        });

        // ── Protección en la propia base de datos (antes solo la aplicación lo impedía) ──
        // Borrar un producto ya no arrastra su kardex ni deja ítems de comprobantes sin producto.
        Schema::table('movimientos_stock', function (Blueprint $table) {
            $table->dropForeign(['producto_id']);
            $table->foreign('producto_id')->references('id')->on('productos')->restrictOnDelete();
        });
        Schema::table('comprobante_items', function (Blueprint $table) {
            $table->dropForeign(['producto_id']);
            $table->foreign('producto_id')->references('id')->on('productos')->restrictOnDelete();
        });

        // Borrar una empresa ya no arrastra comprobantes, ventas, pagos, kardex ni la bitácora.
        foreach (self::DE_LA_EMPRESA as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropForeign(['empresa_id']);
                $table->foreign('empresa_id')->references('id')->on('empresas')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::DE_LA_EMPRESA as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropForeign(['empresa_id']);
                $table->foreign('empresa_id')->references('id')->on('empresas')->cascadeOnDelete();
            });
        }
        Schema::table('comprobante_items', function (Blueprint $table) {
            $table->dropForeign(['producto_id']);
            $table->foreign('producto_id')->references('id')->on('productos')->nullOnDelete();
        });
        Schema::table('movimientos_stock', function (Blueprint $table) {
            $table->dropForeign(['producto_id']);
            $table->foreign('producto_id')->references('id')->on('productos')->cascadeOnDelete();
        });
        Schema::table('comprobantes', fn (Blueprint $table) => $table->dropColumn('igv_gratuitas'));
        Schema::table('comprobante_items', fn (Blueprint $table) => $table->dropColumn('valor_referencial'));
    }
};
