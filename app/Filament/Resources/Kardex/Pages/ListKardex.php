<?php

namespace App\Filament\Resources\Kardex\Pages;

use App\Filament\Resources\Kardex\KardexResource;
use Filament\Resources\Pages\ListRecords;

class ListKardex extends ListRecords
{
    protected static string $resource = KardexResource::class;

    public function getTitle(): string
    {
        return 'Kardex: entradas y salidas';
    }
}
