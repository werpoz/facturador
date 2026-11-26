<?php

namespace App\Providers;

use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registro con alcance de petición (ver Comprobante::esRecienCreado)
        $this->app->scoped('comprobantes.recien_creados', fn () => new \ArrayObject);
    }

    public function boot(): void
    {
        // Formato peruano en todo el panel: S/ 1,234.50 y fechas dd/mm/aaaa
        Table::configureUsing(fn (Table $table) => $table
            ->defaultNumberLocale('es_PE')
            ->defaultCurrency('PEN')
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i'));

        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultNumberLocale('es_PE')
            ->defaultCurrency('PEN')
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i'));
    }
}
