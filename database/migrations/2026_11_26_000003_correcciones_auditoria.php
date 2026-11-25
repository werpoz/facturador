<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            // Datos del cliente tal como eran AL EMITIR. Si luego se edita el cliente,
            // el comprobante ya emitido se sigue imprimiendo y reenviando con estos datos.
            $table->string('cliente_tipo_documento', 1)->nullable()->after('cliente_id');
            $table->string('cliente_numero_documento', 15)->nullable()->after('cliente_tipo_documento');
            $table->string('cliente_razon_social')->nullable()->after('cliente_numero_documento');
            $table->string('cliente_direccion')->nullable()->after('cliente_razon_social');

            // Momento exacto en que recibió su número (la hora de emisión que se informa a SUNAT)
            $table->timestamp('emitido_at')->nullable()->after('fecha_emision');

            // Los enlaces firmados de un PSE pueden superar los 255 caracteres
            $table->text('enlace_pdf')->nullable()->change();
            $table->text('enlace_xml')->nullable()->change();
            $table->text('enlace_cdr')->nullable()->change();
        });

        Schema::table('comprobante_items', function (Blueprint $table) {
            // SUNAT admite hasta 10 decimales en el valor unitario; con 4 se descuadraba en ventas por volumen
            $table->decimal('valor_unitario', 22, 10)->change();
        });
    }

    public function down(): void
    {
        Schema::table('comprobante_items', fn (Blueprint $table) => $table->decimal('valor_unitario', 14, 4)->change());
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropColumn(['cliente_tipo_documento', 'cliente_numero_documento', 'cliente_razon_social', 'cliente_direccion', 'emitido_at']);
        });
    }
};
