<?php

namespace App\Filament\Resources\OrdenCompraResource\Pages;

use App\Filament\Resources\OrdenCompraResource;
use App\Filament\Resources\ResumenPedidosResource;
use App\Filament\Resources\OrdenCompraResource\Widgets\ResumenPedidosTableWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOrdenCompras extends ListRecords
{
    protected static string $resource = OrdenCompraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('crear_resumen')
                ->label('Crear resumen')
                ->icon('heroicon-o-document-plus')
                ->color('success')
                ->url(fn() => ResumenPedidosResource::getUrl('create'))
                ->openUrlInNewTab(),
        ];
    }

   /*  protected function getFooterWidgets(): array
    {
        return [
            ResumenPedidosTableWidget::class,
        ];
    } */
}
