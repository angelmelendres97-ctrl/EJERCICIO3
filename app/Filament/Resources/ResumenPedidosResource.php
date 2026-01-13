<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ResumenPedidosResource\Pages;
use App\Filament\Resources\ResumenPedidosResource\RelationManagers;
use App\Models\ResumenPedidos;
use Config;
use DB;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Models\Empresa;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Components\Actions;
use Filament\Actions\StaticAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\View;
use Illuminate\Database\Eloquent\Model; // ESTA LÍNEA ES NECESARIA

class ResumenPedidosResource extends Resource
{
    protected static ?string $model = ResumenPedidos::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getExternalConnectionName(int $empresaId): ?string
    {
        $empresa = Empresa::find($empresaId);
        if (!$empresa || !$empresa->status_conexion) {
            return null;
        }

        $connectionName = 'external_db_' . $empresaId;

        if (!Config::has("database.connections.{$connectionName}")) {
            $dbConfig = [
                'driver' => $empresa->motor,
                'host' => $empresa->host,
                'port' => $empresa->puerto,
                'database' => $empresa->nombre_base,
                'username' => $empresa->usuario,
                'password' => $empresa->clave,
                'charset' => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix' => '',
                'options' => [
                    \PDO::ATTR_PERSISTENT => true,
                ],
            ];
            Config::set("database.connections.{$connectionName}", $dbConfig);
        }

        return $connectionName;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Conexión y Empresa')
                    ->schema([
                        Forms\Components\Select::make('id_empresa')
                            ->label('Conexion')
                            ->relationship('empresa', 'nombre_empresa')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required(),
                        Forms\Components\Select::make('amdg_id_empresa')
                            ->label('Empresa')
                            ->options(function (Get $get) {
                                $empresaId = $get('id_empresa');
                                if (!$empresaId)
                                    return [];
                                $connectionName = self::getExternalConnectionName($empresaId);
                                if (!$connectionName)
                                    return [];
                                try {
                                    return DB::connection($connectionName)->table('saeempr')->pluck('empr_nom_empr', 'empr_cod_empr')->all();
                                } catch (\Exception $e) {
                                    return [];
                                }
                            })
                            ->searchable()->live()->required(),
                        Forms\Components\Select::make('amdg_id_sucursal')
                            ->label('Sucursal')
                            ->options(function (Get $get) {
                                $empresaId = $get('id_empresa');
                                $amdgIdEmpresaCode = $get('amdg_id_empresa');
                                if (!$empresaId || !$amdgIdEmpresaCode)
                                    return [];
                                $connectionName = self::getExternalConnectionName($empresaId);
                                if (!$connectionName)
                                    return [];
                                try {
                                    return DB::connection($connectionName)->table('saesucu')->where('sucu_cod_empr', $amdgIdEmpresaCode)->pluck('sucu_nom_sucu', 'sucu_cod_sucu')->all();
                                } catch (\Exception $e) {
                                    return [];
                                }
                            }),

                        Forms\Components\Select::make(name: 'tipo_presupuesto')
                            ->label('Presupuesto:')
                            ->options(['AZ' => 'AZ', 'PB' => 'PB'])
                            ->required(),

                    ])->columns(3),

                Forms\Components\Section::make('Traer información Ordenes Compra')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\DatePicker::make('fecha_desde')
                                    ->label('Fecha Desde')
                                    ->default(now()->startOfDay()),
                                Forms\Components\DatePicker::make('fecha_hasta')
                                    ->label('Fecha Hasta')
                                    ->default(now()->endOfDay()),
                            ]),
                        Forms\Components\Repeater::make('ordenes_compra')
                            ->schema([
                                Forms\Components\TextInput::make('id_orden_compra')->label('Secuencial')->readOnly()->columnSpan(2),
                                Forms\Components\TextInput::make('nombre_empresa')->label('Conexion')->readOnly()->columnSpan(3),
                                Forms\Components\TextInput::make('proveedor')->label('Preveedor')->readOnly()->columnSpan(3),
                                Forms\Components\TextInput::make('total_fact')->label('Total')->readOnly()->prefix('$')->columnSpan(2),
                                Forms\Components\DatePicker::make('fecha_oc')->label('Fecha OC')->readOnly()->columnSpan(2),
                                Forms\Components\Actions::make([
                                    Action::make('ver_detalle')
                                        ->label('Ver')
                                        ->icon('heroicon-o-eye')
                                        ->modalContent(function (Get $get) {
                                            $ordenCompraId = $get('id_orden_compra');
                                            if (!$ordenCompraId) {
                                                return 'No se pudo cargar el detalle.';
                                            }
                                            $detalles = \App\Models\DetalleOrdenCompra::where('id_orden_compra', $ordenCompraId)->get();
                                            return view('filament.resources.resumen-pedidos-resource.widgets.detalle-orden-compra-modal', ['detalles' => $detalles]);
                                        })
                                        ->modalSubmitAction(false)
                                        ->modalCancelAction(fn(StaticAction $action) => $action->label('Cerrar')),
                                ])->columnSpan(2),
                                Forms\Components\Checkbox::make('checkbox_oc')->label('Marcar')->columnSpan(1),

                            ])
                            ->columns(16)
                            ->reorderable(false)
                            ->addable(false)
                            ->deletable(false)
                            ->grid(columns: 1)
                    ])
                    ->headerActions([
                        Action::make('consultar_ordenes_compra')
                            ->label('Consultar Ordenes Compra')
                            ->icon('heroicon-o-magnifying-glass')
                            ->action(function (Get $get, Set $set) {
                                $id_empresa = $get('id_empresa');
                                $amdg_id_empresa = $get('amdg_id_empresa');
                                $amdg_id_sucursal = $get('amdg_id_sucursal');
                                $fecha_desde = $get('fecha_desde');
                                $fecha_hasta = $get('fecha_hasta');

                                if (!$id_empresa || !$amdg_id_empresa || !$amdg_id_sucursal) {
                                    return;
                                }

                                $ordenesExistentes = \App\Models\DetalleResumenPedidos::pluck('id_orden_compra')->all();

                                $query = \App\Models\OrdenCompra::query()
                                    ->where('id_empresa', $id_empresa)
                                    ->where('amdg_id_empresa', $amdg_id_empresa)
                                    ->where('amdg_id_sucursal', $amdg_id_sucursal)
                                    ->whereNotIn('id', $ordenesExistentes)
                                    ->where('anulada', false);

                                if (!empty($fecha_desde) && !empty($fecha_hasta)) {
                                    $query->whereBetween('fecha_pedido', [$fecha_desde, $fecha_hasta]);
                                }

                                $ordenes = $query->get();

                                $pedidos = $ordenes->map(function ($orden) {
                                    return [
                                        'id_orden_compra' => $orden->id,
                                        'id_conexion' => $orden->id_empresa,
                                        'nombre_empresa' => $orden->empresa->nombre_empresa ?? '',
                                        'proveedor' => $orden->proveedor,
                                        'total_fact' => $orden->total,
                                        'fecha_oc' => $orden->fecha_pedido ? $orden->fecha_pedido->format('Y-m-d') : null,
                                    ];
                                })->toArray();

                                $set('ordenes_compra', $pedidos);
                            })
                        //->visible(fn(Get $get) => !empty($get('id_empresa')) && !empty($get('amdg_id_empresa')) && !empty($get('amdg_id_sucursal')))
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->actionsPosition(\Filament\Tables\Enums\ActionsPosition::BeforeColumns)
            ->columns([
                Tables\Columns\TextColumn::make('codigo_secuencial')
                    ->label('Secuencial')
                    ->formatStateUsing(fn(string $state): string => str_pad($state, 8, '0', STR_PAD_LEFT))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('empresa.nombre_empresa')
                    ->label('Conexión')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amdg_id_empresa')
                    ->label('Empresa')
                    ->sortable()
                    ->getStateUsing(function (object $record) {
                        $empresaId = $record->id_empresa;
                        $amdg_id_empresa = $record->amdg_id_empresa;

                        if (!$empresaId || !$amdg_id_empresa) {
                            return 'N/A (Faltan IDs)';
                        }

                        $connectionName = self::getExternalConnectionName($empresaId);

                        if (!$connectionName) {
                            return 'N/A (No hay conexión)';
                        }

                        try {
                            $empresa = DB::connection($connectionName)
                                ->table('saeempr')
                                ->where('empr_cod_empr', $amdg_id_empresa)
                                ->select(DB::raw(" '(' || empr_cod_empr || ') ' || empr_nom_empr AS nombre_empresa"))
                                ->first();

                            return $empresa->nombre_empresa ?? 'Empresa no encontrada';
                        } catch (\Exception $e) {
                            return 'Error DB';
                        }
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Presupuesto')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'PB' => 'warning',
                        'AZ' => 'success',
                        default => 'gray',
                    })
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('usuario.name')
                    ->label('Creado Por')
                    ->sortable()
                    ->toggleable(),


                Tables\Columns\TextColumn::make('descripcion')
                    ->label('Descripción')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha Creación')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('ver_ordenes')
                    ->label('Ver Órdenes')
                    ->icon('heroicon-o-eye')
                    ->modalContent(fn($record) => view(
                        'filament.resources.resumen-pedidos-resource.widgets.ordenes-compra-modal',
                        ['record' => $record]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelAction(fn(StaticAction $action) => $action->label('Cerrar')),
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->url(fn(ResumenPedidos $record) => route('resumen-pedidos.pdf', $record))
                    ->openUrlInNewTab(),
                //Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResumenPedidos::route('/'),
            'create' => Pages\CreateResumenPedidos::route('/create'),
            'edit' => Pages\EditResumenPedidos::route('/{record}/edit'),
        ];
    }
}
