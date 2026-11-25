<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('ruc', 11)->unique();
            $table->string('razon_social');
            $table->string('nombre_comercial')->nullable();
            $table->string('direccion')->nullable();
            $table->string('ubigeo', 6)->nullable();
            $table->string('departamento')->nullable();
            $table->string('provincia')->nullable();
            $table->string('distrito')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->string('logo')->nullable();
            // Credenciales del PSE (se guardan cifradas con el cast 'encrypted')
            $table->string('pse_proveedor')->default('fake'); // fake | gior
            $table->string('pse_entorno')->default('beta');   // beta | produccion
            $table->text('pse_token')->nullable();
            $table->timestamps();
        });

        Schema::create('empresa_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rol')->default('admin'); // admin | cajero | contador
            $table->timestamps();
            $table->unique(['empresa_id', 'user_id']);
        });

        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->string('tipo_comprobante', 2);   // Catálogo 01: 01, 03, 07, 08, 09
            $table->string('serie', 4);              // F001, B001, FC01...
            $table->unsignedInteger('correlativo')->default(0); // último número usado
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'serie']);
        });

        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->string('tipo_documento', 1);     // Catálogo 06: 0, 1, 4, 6, 7, A
            $table->string('numero_documento', 15);
            $table->string('razon_social');
            $table->string('direccion')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono')->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'tipo_documento', 'numero_documento']);
        });

        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->string('codigo', 30)->nullable();
            $table->string('descripcion');
            $table->string('unidad_medida', 3)->default('NIU'); // Catálogo 03 (UN/ECE rec 20)
            $table->decimal('precio_unitario', 14, 2);          // Precio con IGV incluido
            $table->string('tipo_afectacion_igv', 2)->default('10'); // Catálogo 07
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('comprobantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained()->restrictOnDelete();
            $table->foreignId('serie_id')->constrained()->restrictOnDelete();
            $table->string('tipo_comprobante', 2);
            $table->string('serie', 4);
            $table->unsignedInteger('correlativo')->nullable(); // se asigna al emitir
            $table->date('fecha_emision');
            $table->date('fecha_vencimiento')->nullable();
            $table->string('moneda', 3)->default('PEN');
            $table->string('forma_pago')->default('Contado'); // Contado | Credito

            // Nota de crédito / débito
            $table->foreignId('comprobante_referencia_id')->nullable()->constrained('comprobantes')->nullOnDelete();
            $table->string('motivo_codigo', 2)->nullable();     // Catálogo 09 / 10
            $table->string('motivo_descripcion')->nullable();

            // Totales (siempre DECIMAL, nunca float)
            $table->decimal('op_gravadas', 14, 2)->default(0);
            $table->decimal('op_exoneradas', 14, 2)->default(0);
            $table->decimal('op_inafectas', 14, 2)->default(0);
            $table->decimal('op_gratuitas', 14, 2)->default(0);
            $table->decimal('igv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // Estado frente al PSE / SUNAT
            $table->string('estado')->default('borrador');
            $table->string('sunat_codigo')->nullable();
            $table->text('sunat_mensaje')->nullable();
            $table->string('hash')->nullable();
            $table->string('enlace_pdf')->nullable();
            $table->string('enlace_xml')->nullable();
            $table->string('enlace_cdr')->nullable();
            $table->unsignedSmallInteger('intentos_envio')->default(0);
            $table->timestamp('enviado_at')->nullable();

            $table->text('observaciones')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'serie', 'correlativo']);
            $table->index(['empresa_id', 'fecha_emision']);
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('comprobante_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained()->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained()->nullOnDelete();
            $table->string('codigo', 30)->nullable();
            $table->string('descripcion');
            $table->string('unidad_medida', 3)->default('NIU');
            $table->decimal('cantidad', 14, 4);
            $table->decimal('precio_unitario', 14, 4);  // con IGV
            $table->decimal('valor_unitario', 14, 4);   // sin IGV
            $table->string('tipo_afectacion_igv', 2)->default('10');
            $table->decimal('valor_venta', 14, 2);      // cantidad * valor_unitario
            $table->decimal('igv', 14, 2);
            $table->decimal('total', 14, 2);            // valor_venta + igv
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('logs_envio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained()->cascadeOnDelete();
            $table->string('accion');                // enviar | consultar | anular
            $table->string('proveedor');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->boolean('exitoso')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_envio');
        Schema::dropIfExists('comprobante_items');
        Schema::dropIfExists('comprobantes');
        Schema::dropIfExists('productos');
        Schema::dropIfExists('clientes');
        Schema::dropIfExists('series');
        Schema::dropIfExists('empresa_user');
        Schema::dropIfExists('empresas');
    }
};
