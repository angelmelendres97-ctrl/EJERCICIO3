<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrdenCompraResource\Pages;
use App\Models\OrdenCompra;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\Empresa;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Get;
use Filament\Forms\Set;
use App\Filament\Resources\ProveedorResource;
use App\Filament\Resources\ProductoResource;
use App\Models\Proveedores;
use App\Models\Producto;
use App\Services\ProveedorSyncService;
use App\Services\ProductoSyncService;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\View;
use Filament\Actions\StaticAction;
use Illuminate\Database\Eloquent\Model; // ESTA LÍNEA ES NECESARIA
use Filament\Notifications\Notification;

class OrdenCompraResource extends Resource
{
    protected static ?string $model = OrdenCompra::class;

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

                /*
                |--------------------------------------------------------------------------
                | MODAL "Ver Productos de la Orden" (DESACTIVADO TEMPORALMENTE)
                | Motivo: en tu entorno está fallando por métodos no disponibles.
                |--------------------------------------------------------------------------
                */
                // Actions::make([
                //     Action::make('verProductos')
                //         ->label('Ver Productos de la Orden')
                //         ->action(function (OrdenCompra $record) {
                //             // No action needed here, it just opens the modal
                //         })
                //         ->modalContent(fn(OrdenCompra $record): \Illuminate\Contracts\View\View => view(
                //             'filament.resources.orden-compra-resource.actions.ver-productos',
                //             ['detalles' => $record->detalles],
                //         ))
                //         ->modalSubmitAction(false)
                //         ->modalCancelAction(fn(StaticAction $action) => $action->label('Cerrar'))
                //         ->color('info')
                //         ->icon('heroicon-o-eye'),
                // ])
                //     ->columnSpanFull()
                //     ->visible(fn($record) => $record !== null),

                Forms\Components\Section::make('Conexión y Empresa')
                    ->schema([
                        Forms\Components\Select::make('id_empresa')
                            ->label('Conexión')
                            ->relationship('empresa', 'nombre_empresa')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required(),

                        Forms\Components\Select::make('amdg_id_empresa')
                            ->label('Empresa')
                            ->options(function (Get $get) {
                                $empresaId = $get('id_empresa');
                                if (!$empresaId) {
                                    return [];
                                }

                                $connectionName = self::getExternalConnectionName($empresaId);
                                if (!$connectionName) {
                                    return [];
                                }

                                try {
                                    return DB::connection($connectionName)
                                        ->table('saeempr')
                                        ->pluck('empr_nom_empr', 'empr_cod_empr')
                                        ->all();
                                } catch (\Exception $e) {
                                    return [];
                                }
                            })
                            ->searchable()
                            ->live()
                            ->required(),

                        Forms\Components\Select::make('amdg_id_sucursal')
                            ->label('Sucursal')
                            ->options(function (Get $get) {
                                $empresaId = $get('id_empresa');
                                $amdgIdEmpresaCode = $get('amdg_id_empresa');

                                if (!$empresaId || !$amdgIdEmpresaCode) {
                                    return [];
                                }

                                $connectionName = self::getExternalConnectionName($empresaId);
                                if (!$connectionName) {
                                    return [];
                                }

                                try {
                                    return DB::connection($connectionName)
                                        ->table('saesucu')
                                        ->where('sucu_cod_empr', $amdgIdEmpresaCode)
                                        ->pluck('sucu_nom_sucu', 'sucu_cod_sucu')
                                        ->all();
                                } catch (\Exception $e) {
                                    return [];
                                }
                            })
                            ->searchable()
                            ->live()
                            ->required(),
                    ])->columns(3),

                /*
                |--------------------------------------------------------------------------
                | ESTE MODAL SÍ SE REACTIVA (Importar desde Pedido)
                |--------------------------------------------------------------------------
                */
                Forms\Components\Section::make('Información Presupuesto')
                    ->headerActions([
                        Action::make('importar_pedido')
                            ->label('Importar desde Pedido')
                            ->icon('heroicon-o-magnifying-glass')
                            ->modalContent(function (Get $get) {
                                $id_empresa = $get('id_empresa');
                                $amdg_id_empresa = $get('amdg_id_empresa');
                                $amdg_id_sucursal = $get('amdg_id_sucursal');
                                $pedidos_importados = $get('pedidos_importados');

                                return view('livewire.buscar-pedidos-compra-container', compact(
                                    'id_empresa',
                                    'amdg_id_empresa',
                                    'amdg_id_sucursal',
                                    'pedidos_importados'
                                ));
                            })
                            ->modalHeading('Buscar Pedidos de Compra para Importar')
                            ->modalSubmitAction(false)
                            ->modalCancelAction(fn(StaticAction $action) => $action->label('Cerrar'))
                            ->visible(fn(Get $get) => !empty($get('id_empresa')) && !empty($get('amdg_id_empresa')) && !empty($get('amdg_id_sucursal')))
                    ])
                    ->schema([

                        Forms\Components\TextInput::make('pedidos_importados')
                            ->label('Pedidos Importados')
                            ->readOnly()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('uso_compra')
                            ->label('Para Uso De:')
                            ->required()
                            ->maxLength(2550)
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('solicitado_por')
                            ->label('Solicitado Por:')
                            ->required()
                            ->default(fn() => auth()->user()?->name)
                            ->maxLength(2550)
                            ->columnSpan(2),

                        Forms\Components\Select::make(name: 'formato')
                            ->label('Formato:')
                            ->options(['F' => 'FACTURA', 'P' => 'PROFORMA'])
                            ->required(),

                        Forms\Components\TextInput::make('numero_factura_proforma')
                            ->label(fn(Get $get) => $get('formato') === 'P' ? 'Número de proforma' : 'Número de factura')
                            ->helperText('Ingrese el número según el formato seleccionado.')
                            ->visible(fn(Get $get) => filled($get('formato')))
                            ->maxLength(255),

                        Forms\Components\Select::make(name: 'tipo_oc')
                            ->label('Tipo Orden Compra:')
                            ->options([
                                'REEMB' => 'REEMBOLSO',
                                'COMPRA' => 'COMPRA',
                                'PAGO' => 'PAGO',
                                'REGUL' => 'REGULARIZACIÓN',
                                'CAJAC' => 'CAJA CHICA'
                            ])
                            ->required(),

                        Forms\Components\TextInput::make('nombre_reembolso')
                            ->label('Nombre de a quien se reembolsa')
                            ->visible(fn(Get $get) => $get('tipo_oc') === 'REEMB')
                            ->maxLength(255),

                        Forms\Components\Select::make(name: 'presupuesto')
                            ->label('Presupuesto:')
                            ->options(['AZ' => 'AZ', 'PB' => 'PB'])
                            ->required(),

                    ])->columns(4),

                Forms\Components\Section::make('Información General')
                    ->schema([
                        Forms\Components\TextInput::make('id_proveedor')
                            ->numeric()
                            ->required()
                            ->label('ID Proveedor')
                            ->readOnly()
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('identificacion')
                            ->maxLength(20)
                            ->label('Identificación (RUC/DNI)')
                            ->readOnly()
                            ->columnSpan(1),

                        Forms\Components\Hidden::make('proveedor'),

                        Forms\Components\Select::make('info_proveedor')
                            ->label('Proveedor')
                            ->options(function (Get $get) {
                                $empresaId = $get('id_empresa');
                                $amdg_id_empresa = $get('amdg_id_empresa');

                                if (!$empresaId) {
                                    return [];
                                }

                                $connectionName = self::getExternalConnectionName($empresaId);
                                if (!$connectionName) {
                                    return [];
                                }

                                try {
                                    return DB::connection($connectionName)
                                        ->table('saeclpv')
                                        ->where('clpv_cod_empr', $amdg_id_empresa)
                                        ->where('clpv_clopv_clpv', 'PV')
                                        ->select([
                                            'clpv_cod_clpv',
                                            DB::raw("clpv_nom_clpv || ' (' || clpv_ruc_clpv || ')' AS proveedor_etiqueta")
                                        ])
                                        ->pluck('proveedor_etiqueta', 'clpv_cod_clpv')
                                        ->all();
                                } catch (\Exception $e) {
                                    return [];
                                }
                            })
                            ->searchable()
                            ->live()
                            ->required()
                            ->columnSpan(2)
                            ->suffixAction(
                                /*
                                |--------------------------------------------------------------------------
                                | Antes: modal para crear proveedor (DESACTIVADO).
                                | Ahora: link directo a /admin/proveedors/create
                                |--------------------------------------------------------------------------
                                */
                                Action::make('ir_crear_proveedor')
                                    ->label('+')
                                    ->tooltip('Crear proveedor')
                                    ->icon('heroicon-o-plus')
                                    ->url(fn() => url('/admin/proveedors/create'))
                                    ->openUrlInNewTab()
                            )
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                                if (empty($state)) {
                                    $set('identificacion', null);
                                    return;
                                }

                                $empresaId = $get('id_empresa');
                                $amdg_id_empresa = $get('amdg_id_empresa');

                                $connectionName = self::getExternalConnectionName($empresaId);
                                if (!$connectionName) {
                                    $set('identificacion', null);
                                    return;
                                }

                                $data = DB::connection($connectionName)
                                    ->table('saeclpv')
                                    ->where('clpv_cod_clpv', $state)
                                    ->where('clpv_cod_empr', $amdg_id_empresa)
                                    ->select('clpv_ruc_clpv', 'clpv_cod_clpv', 'clpv_nom_clpv')
                                    ->first();

                                if ($data) {
                                    $set('identificacion', $data->clpv_ruc_clpv);
                                    $set('id_proveedor', $data->clpv_cod_clpv);
                                    $set('proveedor', $data->clpv_nom_clpv);
                                } else {
                                    $set('identificacion', null);
                                    $set('id_proveedor', null);
                                    $set('proveedor', null);
                                }
                            }),

                        Forms\Components\Select::make('trasanccion')
                            ->label('Transacción')
                            ->options(function (Get $get) {
                                $empresaId = $get('id_empresa');
                                $amdg_id_empresa = $get('amdg_id_empresa');
                                $amdg_id_sucursal = $get('amdg_id_sucursal');

                                if (!$empresaId) {
                                    return [];
                                }

                                $connectionName = self::getExternalConnectionName($empresaId);
                                if (!$connectionName) {
                                    return [];
                                }

                                try {
                                    return DB::connection($connectionName)
                                        ->table('saetran as t')
                                        ->join('saedefi as d', 't.tran_cod_tran', '=', 'd.defi_cod_tran')
                                        ->where('t.tran_cod_empr', $amdg_id_empresa)
                                        ->where('t.tran_cod_sucu', $amdg_id_sucursal)
                                        ->where('t.tran_cod_modu', 10)
                                        ->where('d.defi_cod_empr', $amdg_id_empresa)
                                        ->where('d.defi_tip_defi', '4')
                                        ->where('d.defi_cod_modu', 10)
                                        ->select([
                                            't.tran_des_tran',
                                            DB::raw("t.tran_des_tran || ' (' || t.tran_cod_tran || ')' AS transaccion_etiqueta")
                                        ])
                                        ->groupBy('t.tran_des_tran', 'transaccion_etiqueta')
                                        ->orderBy('transaccion_etiqueta', 'asc')
                                        ->pluck('transaccion_etiqueta', 't.tran_des_tran')
                                        ->all();
                                } catch (\Exception $e) {
                                    return [];
                                }
                            })
                            ->searchable()
                            ->live()
                            ->default('ORDEN DE COMPRA')
                            ->required()
                            ->columnSpan(2),

                        Forms\Components\DatePicker::make('fecha_pedido')
                            ->label('Fecha del Pedido')
                            ->default(now())
                            ->required(),

                        Forms\Components\DatePicker::make('fecha_entrega')
                            ->label('Fecha de Entrega Estimada')
                            ->default(now()->addWeek())
                            ->required(),

                        Forms\Components\Textarea::make('observaciones')
                            ->label('Observaciones')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ])->columns(4),

                Forms\Components\Section::make('Productos')
                    ->headerActions([
                        /*
                        |--------------------------------------------------------------------------
                        | Antes: modal para registrar producto (DESACTIVADO).
                        | Ahora: link directo a /admin/products/create
                        |--------------------------------------------------------------------------
                        */
                        Action::make('ir_crear_producto')
                            ->label('+ Registrar nuevo producto')
                            ->icon('heroicon-o-plus')
                            ->url(fn() => url('/admin/productos/create'))
                            ->openUrlInNewTab(),
                    ])
                    ->schema([
                        Forms\Components\Repeater::make('detalles')
                            ->schema([
                                Grid::make(14)
                                    ->schema([
                                        Forms\Components\Placeholder::make('detalle_auxiliar')
                                            ->label('Producto auxiliar')
                                            ->content(fn(Get $get) => $get('detalle'))
                                            ->visible(fn(Get $get) => filled($get('detalle')))
                                            ->columnSpan(['default' => 12, 'lg' => 14]),

                                        Forms\Components\Select::make('id_bodega')
                                            ->label('Bodega')
                                            ->placeholder('Seleccione')
                                            ->options(function (Get $get) {
                                                $empresaId = $get('../../id_empresa');
                                                $amdgIdEmpresaCode = $get('../../amdg_id_empresa');
                                                $amdg_id_sucursal = $get('../../amdg_id_sucursal');

                                                if (!$empresaId || !$amdgIdEmpresaCode) {
                                                    return [];
                                                }

                                                $connectionName = self::getExternalConnectionName($empresaId);
                                                if (!$connectionName) {
                                                    return [];
                                                }

                                                try {
                                                    return DB::connection($connectionName)
                                                        ->table('saebode')
                                                        ->join('saesubo', 'subo_cod_bode', '=', 'bode_cod_bode')
                                                        ->where('subo_cod_empr', $amdgIdEmpresaCode)
                                                        ->where('bode_cod_empr', $amdgIdEmpresaCode)
                                                        ->where('subo_cod_sucu', $amdg_id_sucursal)
                                                        ->pluck('bode_nom_bode', 'bode_cod_bode')
                                                        ->all();
                                                } catch (\Exception $e) {
                                                    return [];
                                                }
                                            })
                                            ->searchable()
                                            ->live()
                                            ->required()
                                            ->columnSpan(['default' => 12, 'lg' => 2]),

                                        Forms\Components\Select::make('codigo_producto')
                                            ->label('Producto')
                                            ->helperText(fn(Get $get) => filled($get('detalle')) ? 'Producto auxiliar. Debe seleccionar un producto real del inventario.' : null)
                                            ->options(function (Get $get) {
                                                $empresaId = $get('../../id_empresa');
                                                $amdg_id_empresa = $get('../../amdg_id_empresa');
                                                $amdg_id_sucursal = $get('../../amdg_id_sucursal');
                                                $id_bodega = $get('id_bodega');

                                                if (!$empresaId || !$id_bodega) {
                                                    return [];
                                                }

                                                $connectionName = self::getExternalConnectionName($empresaId);
                                                if (!$connectionName) {
                                                    return [];
                                                }

                                                try {
                                                    return DB::connection($connectionName)
                                                        ->table('saeprod')
                                                        ->join('saeprbo', 'prbo_cod_prod', '=', 'prod_cod_prod')
                                                        ->where('prod_cod_sucu', $amdg_id_sucursal)
                                                        ->where('prod_cod_empr', $amdg_id_empresa)
                                                        ->where('prbo_cod_empr', $amdg_id_empresa)
                                                        ->where('prbo_cod_sucu', $amdg_id_sucursal)
                                                        ->where('prbo_cod_bode', $id_bodega)
                                                        ->select([
                                                            'prod_cod_prod',
                                                            DB::raw("prod_nom_prod || ' (' || prod_cod_prod || ')' AS productos_etiqueta")
                                                        ])
                                                        ->orderBy('productos_etiqueta', 'asc')
                                                        ->pluck('productos_etiqueta', 'prod_cod_prod');
                                                } catch (\Exception $e) {
                                                    return [];
                                                }
                                            })
                                            ->searchable()
                                            ->live()
                                            ->required()
                                            ->columnSpan(['default' => 12, 'lg' => 3])
                                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                                                if (empty($state)) {
                                                    $set('producto', null);
                                                    $set('costo', 0);
                                                    $set('impuesto', 0);
                                                    return;
                                                }

                                                $empresaId = $get('../../id_empresa');
                                                $amdg_id_empresa = $get('../../amdg_id_empresa');
                                                $amdg_id_sucursal = $get('../../amdg_id_sucursal');
                                                $id_bodega = $get('id_bodega');

                                                $connectionName = self::getExternalConnectionName($empresaId);
                                                if (!$connectionName) {
                                                    return;
                                                }

                                                $data = DB::connection($connectionName)
                                                    ->table('saeprod')
                                                    ->join('saeprbo', 'prbo_cod_prod', '=', 'prod_cod_prod')
                                                    ->where('prod_cod_sucu', $amdg_id_sucursal)
                                                    ->where('prod_cod_empr', $amdg_id_empresa)
                                                    ->where('prbo_cod_empr', $amdg_id_empresa)
                                                    ->where('prbo_cod_sucu', $amdg_id_sucursal)
                                                    ->where('prbo_cod_bode', $id_bodega)
                                                    ->where('prbo_cod_prod', $state)
                                                    ->where('prod_cod_prod', $state)
                                                    ->select('prbo_uco_prod', 'prbo_iva_porc', 'prod_nom_prod')
                                                    ->first();

                                                if ($data) {
                                                    $set('costo', number_format($data->prbo_uco_prod, 6, '.', ''));
                                                    $impuesto = round($data->prbo_iva_porc, 2);
                                                    $set('impuesto', $impuesto == 8.0 ? 18 : $impuesto);
                                                    $set('producto', $data->prod_nom_prod . ' (' . $state . ')');
                                                }
                                            }),

                                        Forms\Components\Hidden::make('producto'),
                                        Forms\Components\Hidden::make('detalle'),

                                        Forms\Components\TextInput::make('cantidad')
                                            ->numeric()
                                            ->required()
                                            ->live()
                                            ->default(1)
                                            ->columnSpan(['default' => 12, 'lg' => 1]),

                                        Forms\Components\TextInput::make('costo')
                                            ->numeric()
                                            ->required()
                                            ->live()
                                            ->prefix('$')
                                            ->columnSpan(['default' => 12, 'lg' => 2]),

                                        Forms\Components\TextInput::make('descuento')
                                            ->numeric()
                                            ->required()
                                            ->live()
                                            ->default(0)
                                            ->prefix('$')
                                            ->columnSpan(['default' => 12, 'lg' => 2]),

                                        Forms\Components\Select::make('impuesto')
                                            ->options(['0' => '0%', '5' => '5%', '15' => '15%', '18' => '18%'])
                                            ->required()
                                            ->live()
                                            ->columnSpan(['default' => 12, 'lg' => 1]),

                                        Forms\Components\Placeholder::make('valor_iva')
                                            ->label('IVA')
                                            ->content(function (Get $get) {
                                                $cantidad = floatval($get('cantidad'));
                                                $costo = floatval($get('costo'));
                                                $iva = floatval($get('impuesto'));
                                                $valorIva = ($cantidad * $costo) * ($iva / 100);
                                                return '$' . number_format($valorIva, 4, '.', '');
                                            })
                                            ->columnSpan(['default' => 12, 'lg' => 1]),

                                        Forms\Components\Placeholder::make('total_linea')
                                            ->label('Total Item')
                                            ->content(function (Get $get) {
                                                $cantidad = floatval($get('cantidad'));
                                                $costo = floatval($get('costo'));
                                                $descuento = floatval($get('descuento'));
                                                $iva = floatval($get('impuesto'));

                                                $subtotal = $cantidad * $costo;
                                                $valorIva = $subtotal * ($iva / 100);
                                                $total = ($subtotal + $valorIva) - $descuento;

                                                return '$' . number_format($total, 4, '.', '');
                                            })
                                            ->columnSpan(['default' => 12, 'lg' => 2]),
                                    ]),
                            ])
                            ->relationship()
                            ->columns(1)
                            ->addActionLabel('Agregar Producto')
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                $detalles = $get('detalles');
                                $subtotalGeneral = 0;
                                $descuentoGeneral = 0;
                                $impuestoGeneral = 0;

                                foreach ($detalles as $detalle) {
                                    $cantidad = floatval($detalle['cantidad'] ?? 0);
                                    $costo = floatval($detalle['costo'] ?? 0);
                                    $descuento = floatval($detalle['descuento'] ?? 0);
                                    $porcentajeIva = floatval($detalle['impuesto'] ?? 0);

                                    $subtotalItem = $cantidad * $costo;
                                    $valorIva = $subtotalItem * ($porcentajeIva / 100);

                                    $subtotalGeneral += $subtotalItem;
                                    $descuentoGeneral += $descuento;
                                    $impuestoGeneral += $valorIva;
                                }

                                $totalGeneral = ($subtotalGeneral - $descuentoGeneral) + $impuestoGeneral;

                                $set('subtotal', number_format($subtotalGeneral, 2, '.', ''));
                                $set('total_descuento', number_format($descuentoGeneral, 2, '.', ''));
                                $set('total_impuesto', number_format($impuestoGeneral, 2, '.', ''));
                                $set('total', number_format($totalGeneral, 2, '.', ''));
                            })
                            ->live(),
                    ]),

                // Hidden fields for totals
                Forms\Components\Hidden::make('subtotal')->default(0),
                Forms\Components\Hidden::make('total_descuento')->default(0),
                Forms\Components\Hidden::make('total_impuesto')->default(0),
                Forms\Components\Hidden::make('total')->default(0),

                Section::make('Resumen de Totales')
                    ->schema([
                        Grid::make()
                            ->columns(1)
                            ->extraAttributes(['class' => 'w-full'])
                            ->schema([
                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_subtotal')
                                            ->content('Subtotal')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_subtotal')
                                            ->content(function (Get $get) {
                                                $subtotal = collect($get('detalles'))->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']));
                                                }, 0);
                                                return '$' . number_format($subtotal, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_desc')
                                            ->content('Total Descuentos')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_desc')
                                            ->content(function (Get $get) {
                                                $totalDescuentos = collect($get('detalles'))->sum(fn($item) => floatval($item['descuento']));
                                                return '$' . number_format($totalDescuentos, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_base_iva0')
                                            ->content('Subtotal IVA 0%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_base_iva0')
                                            ->content(function (Get $get) {
                                                $baseIva0 = collect($get('detalles'))->where('impuesto', '0')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']));
                                                }, 0);
                                                return '$' . number_format($baseIva0, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_iva0')
                                            ->content('IVA 0%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_iva0')
                                            ->content(function () {
                                                return '$' . number_format(0, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_base_iva5')
                                            ->content('Subtotal IVA 5%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_base_iva5')
                                            ->content(function (Get $get) {
                                                $baseIva5 = collect($get('detalles'))->where('impuesto', '5')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']));
                                                }, 0);
                                                return '$' . number_format($baseIva5, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_iva5')
                                            ->content('IVA 5%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_iva5')
                                            ->content(function (Get $get) {
                                                $totalIva = collect($get('detalles'))->where('impuesto', '5')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.05);
                                                }, 0);
                                                return '$' . number_format($totalIva, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_base_iva15')
                                            ->content('Subtotal IVA 15%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_base_iva15')
                                            ->content(function (Get $get) {
                                                $baseIva15 = collect($get('detalles'))->where('impuesto', '15')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']));
                                                }, 0);
                                                return '$' . number_format($baseIva15, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_iva15')
                                            ->content('IVA 15%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_iva15')
                                            ->content(function (Get $get) {
                                                $totalIva15 = collect($get('detalles'))->where('impuesto', '15')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.15);
                                                }, 0);
                                                return '$' . number_format($totalIva15, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_base_iva18')
                                            ->content('Subtotal IVA 18%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_base_iva18')
                                            ->content(function (Get $get) {
                                                $baseIva18 = collect($get('detalles'))->where('impuesto', '18')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']));
                                                }, 0);
                                                return '$' . number_format($baseIva18, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_iva18')
                                            ->content('IVA 18%')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_iva18')
                                            ->content(function (Get $get) {
                                                $totalIva18 = collect($get('detalles'))->where('impuesto', '18')->reduce(function ($carry, $item) {
                                                    return $carry + (floatval($item['cantidad']) * floatval($item['costo']) * 0.18);
                                                }, 0);
                                                return '$' . number_format($totalIva18, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_total_impuesto')
                                            ->content('Total Impuestos')
                                            ->extraAttributes(['class' => 'text-right font-semibold'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_total_impuesto')
                                            ->content(function (Get $get) {
                                                $totalImpuestos = collect($get('detalles'))->reduce(function ($carry, $item) {
                                                    $subtotal = floatval($item['cantidad']) * floatval($item['costo']);
                                                    return $carry + ($subtotal * (floatval($item['impuesto']) / 100));
                                                }, 0);
                                                return '$' . number_format($totalImpuestos, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-bold w-32'])
                                            ->hiddenLabel(),
                                    ]),

                                Grid::make()->columns(2)->extraAttributes(['class' => 'flex justify-end mt-2 border-t border-gray-300 dark:border-gray-700 pt-2 gap-4'])
                                    ->schema([
                                        Placeholder::make('lbl_total')
                                            ->content('Total General')
                                            ->extraAttributes(['class' => 'text-right font-extrabold text-lg text-primary-600'])
                                            ->hiddenLabel(),
                                        Placeholder::make('val_total')
                                            ->content(function (Get $get) {
                                                $total = collect($get('detalles'))->reduce(function ($carry, $item) {
                                                    $subtotal = floatval($item['cantidad']) * floatval($item['costo']);
                                                    $valorIva = $subtotal * (floatval($item['impuesto']) / 100);
                                                    $descuento = floatval($item['descuento']);
                                                    return $carry + ($subtotal + $valorIva - $descuento);
                                                }, 0);
                                                return '$' . number_format($total, 2, '.', '');
                                            })
                                            ->extraAttributes(['class' => 'text-right font-extrabold text-xl text-primary-600 w-32'])
                                            ->hiddenLabel(),
                                    ]),
                            ]),
                    ])->columns(1),

            ])->live();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('id')
                    ->label('Código OC')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('empresa.nombre_empresa')
                    ->label('Conexión')
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
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('amdg_id_sucursal')
                    ->label('Sucursal')
                    ->sortable()
                    ->getStateUsing(function (object $record) {
                        $empresaId = $record->id_empresa;
                        $amdg_id_sucursal = $record->amdg_id_sucursal;

                        if (!$empresaId || !$amdg_id_sucursal) {
                            return 'N/A (Faltan IDs)';
                        }

                        $connectionName = self::getExternalConnectionName($empresaId);

                        if (!$connectionName) {
                            return 'N/A (No hay conexión)';
                        }

                        try {
                            $sucursal = DB::connection($connectionName)
                                ->table('saesucu')
                                ->where('sucu_cod_sucu', $amdg_id_sucursal)
                                ->select(DB::raw(" '(' || sucu_cod_sucu || ') ' || sucu_nom_sucu AS nombre_sucursal"))
                                ->first();

                            return $sucursal->nombre_sucursal ?? 'Sucursal no encontrada';
                        } catch (\Exception $e) {
                            return 'Error DB';
                        }
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('identificacion')
                    ->label('Identificación')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('proveedor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('trasanccion')
                    ->label('Transacción')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('fecha_pedido')
                    ->date()
                    ->label('F. Pedido')
                    ->sortable(),

                Tables\Columns\TextColumn::make('fecha_entrega')
                    ->date()
                    ->label('F. Entrega')
                    ->sortable(),

                Tables\Columns\TextColumn::make('uso_compra')
                    ->label('Uso Compra')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                Tables\Columns\TextColumn::make('solicitado_por')
                    ->label('Solicitado Por')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                Tables\Columns\TextColumn::make('formato')
                    ->label('Formato')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'P' => 'PROFORMA',
                        'F' => 'FACTURA',
                        default => 'Desconocido',
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'P' => 'warning',
                        'F' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                Tables\Columns\TextColumn::make('tipo_oc')
                    ->label('Tipo Orden Compra')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'REEMB' => 'REEMBOLSO',
                        'COMPRA' => 'COMPRA',
                        'PAGO' => 'PAGO',
                        'REGUL' => 'REGULARIZACIÓN',
                        'CAJAC' => 'CAJA CHICA',
                        default => 'Desconocido',
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'REEMB' => 'warning',
                        'COMPRA' => 'success',
                        'PAGO' => 'info',
                        'REGUL' => 'danger',
                        'CAJAC' => 'primary',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                Tables\Columns\TextColumn::make('presupuesto')
                    ->label('Formato')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'PB' => 'warning',
                        'AZ' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                Tables\Columns\TextColumn::make('observaciones')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('subtotal')
                    ->money('USD')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_descuento')
                    ->money('USD')
                    ->label('Descuento')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_impuesto')
                    ->money('USD')
                    ->label('Impuesto')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total')
                    ->money('USD')
                    ->label('Total')
                    ->sortable(),

                Tables\Columns\TextColumn::make('pedidos_importados')
                    ->label('Pedidos Importados')
                    ->sortable(),
            ])
            ->filters([
                // Aquí puedes añadir filtros si es necesario
            ])
            ->actions([

                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->url(fn(OrdenCompra $record) => route('orden-compra.pdf', $record))
                    ->openUrlInNewTab(),

                /*
                |--------------------------------------------------------------------------
                | MODAL "Ver Productos" (DESACTIVADO TEMPORALMENTE)
                |--------------------------------------------------------------------------
                */
                // Tables\Actions\Action::make('verProductos')
                //     ->label('Ver Productos')
                //     ->icon('heroicon-o-eye')
                //     ->color('info')
                //     ->modalContent(fn(OrdenCompra $record): \Illuminate\Contracts\View\View => view(
                //         'filament.resources.orden-compra-resource.actions.ver-productos',
                //         ['detalles' => $record->detalles],
                //     ))
                //     ->modalSubmitAction(false)
                //     ->modalCancelAction(fn(StaticAction $action) => $action->label('Cerrar')),

                Tables\Actions\EditAction::make()
                    ->visible(fn() => auth()->user()->can('Actualizar')),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn() => auth()->user()->can('Borrar'))
                    ->after(function ($record) {
                        \App\Services\OrdenCompraSyncService::eliminar($record);
                    }),
            ])
            ->bulkActions([
                // Acciones masivas
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
            'index' => Pages\ListOrdenCompras::route('/'),
            'create' => Pages\CreateOrdenCompra::route('/create'),
            'edit' => Pages\EditOrdenCompra::route('/{record}/edit'),
        ];
    }
}
