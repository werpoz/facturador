<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('regimen_tributario')->default('mype')->after('ruc'); // nrus | rer | mype | general
        });

        // La VENTA es el hecho comercial; los comprobantes son su representación ante SUNAT.
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('numero');                 // correlativo interno por empresa (V-000001)
            $table->date('fecha');
            $table->string('moneda', 3)->default('PEN');
            $table->decimal('total', 14, 2)->default(0);       // total del comprobante principal
            $table->decimal('acreditado', 14, 2)->default(0);  // notas de crédito aceptadas
            $table->decimal('debitado', 14, 2)->default(0);    // notas de débito aceptadas
            $table->decimal('neto', 14, 2)->default(0);        // total - acreditado + debitado
            $table->string('estado')->default('borrador');
            $table->foreignId('comprobante_principal_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'fecha']);
            $table->index(['empresa_id', 'estado']);
        });

        Schema::table('comprobantes', function (Blueprint $table) {
            $table->foreignId('venta_id')->nullable()->after('empresa_id')->constrained('ventas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venta_id');
        });
        Schema::dropIfExists('ventas');
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('regimen_tributario');
        });
    }
};
