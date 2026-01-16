<?php

namespace App\Filament\Resources\OrdenCompraResource\Actions;

use App\Filament\Resources\OrdenCompraResource;
use App\Filament\Resources\ProveedorResource;
use App\Models\Proveedores;
use App\Services\ProveedorSyncService;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class CreateProveedorModalAction
{
    public static function make(): Action
    {
        return Action::make('crear_proveedor')
            ->label('+')
            ->tooltip('Crear proveedor')
            ->icon('heroicon-o-plus')
            ->modalHeading('Crear proveedor')
            ->modalWidth('7xl')
            ->modalSubmitActionLabel('Crear proveedor')
            ->form(fn(Form $form): Form => $form
                ->schema(ProveedorResource::getFormSchema())
                ->model(Proveedores::class))
            ->mountUsing(function (Action $action): void {
                $data = data_get($action->getLivewire(), 'data', []);

                $action->fillForm([
                    'id_empresa' => $data['id_empresa'] ?? null,
                    'amdg_id_empresa' => $data['amdg_id_empresa'] ?? null,
                    'amdg_id_sucursal' => $data['amdg_id_sucursal'] ?? null,
                ]);
            })
            ->action(function (array $data, Set $set, Get $get): void {
                $record = DB::transaction(function () use ($data) {
                    $record = Proveedores::create($data);

                    $lineasNegocioIds = $data['lineasNegocio'] ?? [];
                    if (!empty($lineasNegocioIds)) {
                        $record->lineasNegocio()->attach($lineasNegocioIds);
                    }

                    ProveedorSyncService::sincronizar($record, $data);

                    return $record;
                });

                $empresaId = $data['id_empresa'] ?? $get('id_empresa');
                $amdgIdEmpresa = $data['amdg_id_empresa'] ?? $get('amdg_id_empresa');
                $connectionName = OrdenCompraResource::getExternalConnectionName((int) $empresaId);

                if ($connectionName) {
                    $proveedor = DB::connection($connectionName)
                        ->table('saeclpv')
                        ->where('clpv_cod_empr', $amdgIdEmpresa)
                        ->where('clpv_ruc_clpv', $data['ruc'])
                        ->where('clpv_clopv_clpv', 'PV')
                        ->select('clpv_cod_clpv', 'clpv_nom_clpv', 'clpv_ruc_clpv')
                        ->first();

                    if ($proveedor) {
                        $set('info_proveedor', $proveedor->clpv_cod_clpv);
                        $set('identificacion', $proveedor->clpv_ruc_clpv);
                        $set('id_proveedor', $proveedor->clpv_cod_clpv);
                        $set('proveedor', $proveedor->clpv_nom_clpv);
                    }
                }

                Notification::make()
                    ->title('Proveedor creado correctamente.')
                    ->success()
                    ->send();
            });
    }
}
