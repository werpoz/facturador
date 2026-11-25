<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cómo se cobró cada venta. Va aparte del comprobante (que es inmutable una vez emitido).
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venta_id')->constrained()->cascadeOnDelete();
            $table->string('medio');                        // efectivo | yape | plin | tarjeta | transferencia
            $table->decimal('monto', 14, 2);                // lo que se cobró por la venta
            $table->decimal('recibido', 14, 2)->nullable(); // solo efectivo: lo que entregó el cliente
            $table->decimal('vuelto', 14, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
