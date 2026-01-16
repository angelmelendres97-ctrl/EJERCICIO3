<?php

namespace App\Filament\Resources\OrdenCompraResource\Actions;

use App\Filament\Resources\ProductoResource;
use App\Models\Producto;
use App\Services\ProductoSyncService;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class CreateProductoModalAction
{
    public static function make(): Action
    {
        return Action::make('registrar_producto')
            ->label('+ Registrar nuevo producto')
            ->icon('heroicon-o-plus')
            ->modalHeading('Registrar nuevo producto')
            ->modalWidth('7xl')
            ->modalSubmitActionLabel('Registrar producto')
            ->form(fn(Form $form): Form => $form
                ->schema(ProductoResource::getFormSchema())
                ->model(Producto::class))
            ->mountUsing(function (Action $action): void {
                $data = data_get($action->getLivewire(), 'data', []);

                $action->fillForm([
                    'id_empresa' => $data['id_empresa'] ?? null,
                    'amdg_id_empresa' => $data['amdg_id_empresa'] ?? null,
                    'amdg_id_sucursal' => $data['amdg_id_sucursal'] ?? null,
                ]);
            })
            ->action(function (array $data, Set $set, Get $get): void {
                DB::transaction(function () use ($data) {
                    $record = Producto::create($data);

                    $lineasNegocioIds = $data['lineasNegocio'] ?? [];
                    if (!empty($lineasNegocioIds)) {
                        $record->lineasNegocio()->attach($lineasNegocioIds);
                    }

                    ProductoSyncService::sincronizar($record, $data);
                });

                $sku = $data['sku'] ?? null;
                $detalles = $get('detalles') ?? [];
                $assigned = false;

                if ($sku) {
                    foreach ($detalles as $index => $detalle) {
                        if (empty($detalle['codigo_producto'] ?? null)) {
                            $set("detalles.{$index}.codigo_producto", $sku);
                            $assigned = true;
                            break;
                        }
                    }
                }

                if (!$assigned) {
                    $set('detalles', $detalles);
                }

                Notification::make()
                    ->title('Producto creado correctamente.')
                    ->success()
                    ->send();
            });
    }
}
