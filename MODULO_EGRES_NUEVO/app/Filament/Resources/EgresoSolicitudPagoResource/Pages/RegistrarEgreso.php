<?php

namespace App\Filament\Resources\EgresoSolicitudPagoResource\Pages;

use App\Filament\Resources\EgresoSolicitudPagoResource;
use App\Filament\Resources\SolicitudPagoResource;
use App\Models\SolicitudPago;
use App\Models\SolicitudPagoDetalle;
use Filament\Actions\Action;
use Filament\Actions\StaticAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class RegistrarEgreso extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = EgresoSolicitudPagoResource::class;

    protected static string $view = 'filament.resources.egreso-solicitud-pago-resource.pages.registrar-egreso';

    protected static ?string $title = 'Registrar egreso';

    protected static bool $shouldRegisterNavigation = false;

    public array $facturasByProvider = [];

    public array $providerContexts = [];

    public array $directorioEntries = [];

    public array $diarioEntries = [];

    public array $paymentMappings = [];

    protected array $catalogCache = [];

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->hydrateProviderData();
    }

    public function getSolicitudProperty(): SolicitudPago
    {
        return $this->record;
    }

    protected function hydrateProviderData(): void
    {
        $detalles = $this->record->loadMissing('detalles')->detalles;

        $this->facturasByProvider = [];
        $this->providerContexts = [];

        foreach ($detalles->groupBy(fn(SolicitudPagoDetalle $detalle) => $this->buildProviderKey($detalle)) as $key => $items) {
            $first = $items->first();
            $context = [
                'conexion' => (int) ($first?->erp_conexion ?? 0),
                'empresa' => $first?->erp_empresa_id,
                'sucursal' => $first?->erp_sucursal,
            ];
            $monedaBase = $this->getMonedaBase($context);
            $cotizacionExterna = $this->getCotizacionExterna($context);

            $this->providerContexts[$key] = $context;

            $this->facturasByProvider[$key] = $items
                ->map(function (SolicitudPagoDetalle $detalle) use ($monedaBase, $cotizacionExterna) {
                    $abono = (float) ($detalle->abono_aplicado ?? 0);
                    $saldo = (float) ($detalle->saldo_al_crear ?? 0);
                    $montos = $this->calculateMontos($abono, 0, $monedaBase, $monedaBase, 1.0, $cotizacionExterna);

                    return [
                        'numero' => $detalle->numero_factura,
                        'fecha_emision' => $detalle->fecha_emision,
                        'fecha_vencimiento' => $detalle->fecha_vencimiento,
                        'saldo' => $saldo,
                        'abono' => $abono,
                        'tipo' => $this->resolveFacturaTipo($detalle),
                        'detalle' => $this->buildDetalleFactura($detalle),
                        'moneda' => $monedaBase,
                        'cotizacion' => 1.0,
                        'debito_local' => $montos['debito_local'],
                        'credito_local' => $montos['credito_local'],
                        'debito_extranjera' => $montos['debito_extranjera'],
                        'credito_extranjera' => $montos['credito_extranjera'],
                        'abono_total' => $abono,
                        'abono_pagado' => 0.0,
                        'saldo_pendiente' => $abono,
                    ];
                })
                ->values()
                ->all();
        }
    }

    protected function buildProviderKey(SolicitudPagoDetalle $detalle): string
    {
        return $this->buildProviderKeyFromValues($detalle->proveedor_codigo, $detalle->proveedor_ruc);
    }

    protected function buildProviderKeyFromValues(?string $codigo, ?string $ruc): string
    {
        return trim((string) $codigo) . '|' . trim((string) $ruc);
    }

    protected function resolveFacturaTipo(SolicitudPagoDetalle $detalle): string
    {
        return strtoupper((string) $detalle->erp_tabla) === 'COMPRA' ? 'CAN' : 'FACTURAS';
    }

    protected function buildDetalleFactura(SolicitudPagoDetalle $detalle): string
    {
        $motivo = $this->record->motivo ?? '';

        return $motivo !== '' ? $motivo : 'Pago factura ' . ($detalle->numero_factura ?? '');
    }

    


    protected function calculateMontos(
        float $debito,
        float $credito,
        ?string $moneda,
        ?string $monedaBase,
        float $cotizacion,
        float $cotizacionExterna
    ): array {
        $debitoLocal = 0.0;
        $creditoLocal = 0.0;
        $debitoExtranjera = 0.0;
        $creditoExtranjera = 0.0;

        if ($moneda && $monedaBase && $moneda !== $monedaBase) {
            $debitoLocal = round($debito * $cotizacion, 2);
            $creditoLocal = round($credito * $cotizacion, 2);
            $debitoExtranjera = round($debito, 2);
            $creditoExtranjera = round($credito, 2);
        } else {
            $debitoLocal = round($debito, 2);
            $creditoLocal = round($credito, 2);
            $debitoExtranjera = $cotizacionExterna > 0 ? round($debito / $cotizacionExterna, 2) : 0.0;
            $creditoExtranjera = $cotizacionExterna > 0 ? round($credito / $cotizacionExterna, 2) : 0.0;
        }

        return [
            'debito_local' => $debitoLocal,
            'credito_local' => $creditoLocal,
            'debito_extranjera' => $debitoExtranjera,
            'credito_extranjera' => $creditoExtranjera,
        ];
    }

    protected function getProvidersQuery(): Builder
    {
        return SolicitudPagoDetalle::query()
            ->selectRaw('
                MIN(id) as id,
                proveedor_codigo,
                proveedor_nombre,
                proveedor_ruc,
                MIN(erp_conexion) as erp_conexion,
                MIN(erp_empresa_id) as erp_empresa_id,
                MIN(erp_sucursal) as erp_sucursal,
                SUM(COALESCE(abono_aplicado, 0)) as total_abono,
                COUNT(*) as facturas_count
            ')
            ->where('solicitud_pago_id', $this->record->getKey())
            ->groupBy('proveedor_codigo', 'proveedor_nombre', 'proveedor_ruc')
            ->orderBy('proveedor_nombre');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getProvidersQuery())
            ->paginated(false)
            ->columns([
                TextColumn::make('proveedor_nombre')
                    ->label('Proveedor')
                    ->description(function (SolicitudPagoDetalle $record): string {
                        $ruc = $record->proveedor_ruc ? 'RUC: ' . $record->proveedor_ruc : null;
                        $codigo = $record->proveedor_codigo ? 'Código: ' . $record->proveedor_codigo : null;
                        $facturas = $record->facturas_count ? $record->facturas_count . ' factura(s)' : null;
                        return collect([$codigo, $ruc, $facturas])->filter()->implode(' · ');
                    })
                    ->searchable(),
                TextColumn::make('total_abono')
                    ->label('Total a pagar')
                    ->formatStateUsing(fn($state) => '$' . number_format((float) $state, 2, '.', ','))
                    ->alignRight(),
                ViewColumn::make('facturas')
                    ->label('Facturas')
                    ->state(function (SolicitudPagoDetalle $record): array {
                        $key = $this->buildProviderKeyFromValues($record->proveedor_codigo, $record->proveedor_ruc);
                        return $this->facturasByProvider[$key] ?? [];
                    })
                    ->view('filament.tables.columns.egreso-facturas'),
            ])
            ->actions([
                Tables\Actions\Action::make('generarDirectorio')
                    ->label('Generar Directorio y Diario')
                    ->icon('heroicon-o-document-text')
                    ->modalHeading(fn(SolicitudPagoDetalle $record) => 'Generar Directorio y Diario - ' . ($record->proveedor_nombre ?? 'Proveedor'))
                    ->form(fn(SolicitudPagoDetalle $record) => $this->getDirectorioFormSchema($record))
                    ->action(function (SolicitudPagoDetalle $record, array $data): void {
                        if (! $this->registrarDirectorioYDiario($record, $data)) {
                            return;
                        }

                        Notification::make()
                            ->title('Directorio y diario generados')
                            ->body('Proveedor: ' . ($record->proveedor_nombre ?? 'N/D'))
                            ->success()
                            ->send();
                    })
                    ->visible(function (SolicitudPagoDetalle $record): bool {
                        return $this->getProviderSaldoPendiente($record) > 0;
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelAction(fn(StaticAction $action) => $action->label('Cancelar')),
            ])
            ->actionsColumnLabel('Acciones');
    }

    protected function registrarDirectorioYDiario(SolicitudPagoDetalle $record, array $data): bool
    {
        $context = $this->resolveProviderContext($record);
        $providerKey = $this->buildProviderKeyFromValues($record->proveedor_codigo, $record->proveedor_ruc);
        $monedaBase = $this->getMonedaBase($context);
        $cotizacionExternaBase = $this->getCotizacionExterna($context);
        $tipoEgreso = $data['tipo_egreso'] ?? 'cheque';

        $detalle = $data['detalle'] ?? $data['detalle_cuenta'] ?? null;
        $facturasBase = collect($this->facturasByProvider[$providerKey] ?? []);
        $totalPendiente = $facturasBase->sum(fn(array $factura) => (float) ($factura['saldo_pendiente'] ?? 0));
        $valor = (float) ($data['valor'] ?? $data['valor_cuenta'] ?? 0);

        if ($valor <= 0 || $valor > $totalPendiente) {
            Notification::make()
                ->title('Valor inválido')
                ->body('El valor debe ser mayor a cero y no superar el saldo pendiente.')
                ->danger()
                ->send();
            return false;
        }

        $facturas = $this->distributePagoFacturas($facturasBase->all(), $valor);

        $moneda = $tipoEgreso === 'cuenta_bancaria'
            ? $monedaBase
            : ($data['moneda'] ?? $monedaBase);
        $cotizacion = $tipoEgreso === 'cuenta_bancaria'
            ? 1.0
            : (float) ($data['cotizacion'] ?? 1);
        $cotizacionExterna = $tipoEgreso === 'cuenta_bancaria'
            ? $cotizacionExternaBase
            : (float) ($data['cotizacion_externa'] ?? 1);

        $directorio = collect($facturas)
            ->map(function (array $factura) use ($data, $moneda, $monedaBase, $record, $cotizacion, $cotizacionExterna) {
                $abono = (float) ($factura['abono_pago'] ?? 0);
                $montos = $this->calculateMontos($abono, 0, $moneda, $monedaBase, $cotizacion, $cotizacionExterna);
                $saldo = (float) ($factura['saldo'] ?? 0);

                return [
                    'proveedor' => $record->proveedor_nombre ?? $record->proveedor_codigo ?? null,
                    'tipo' => $factura['tipo'] ?? null,
                    'factura' => $factura['numero'] ?? '',
                    'fecha_vencimiento' => $factura['fecha_vencimiento'] ?? null,
                    'abono' => $abono,
                    'saldo_pendiente' => max(0, $saldo - $abono),
                    'moneda' => $moneda,
                    'cotizacion' => $cotizacion,
                    'cotizacion_externa' => $cotizacionExterna,
                    'detalle' => $detalle,
                    'debito_local' => $montos['debito_local'],
                    'credito_local' => $montos['credito_local'],
                    'debito_extranjera' => $montos['debito_extranjera'],
                    'credito_extranjera' => $montos['credito_extranjera'],
                    'diario_generado' => true,
                ];
            })
            ->all();

        $totalPago = collect($facturas)->sum(fn(array $factura) => (float) ($factura['abono_pago'] ?? 0));

        $cuentaProveedor = $this->getCuentaProveedor($context, $record->proveedor_codigo);
        $cuentaProveedorNombre = $this->getCuentaContableNombre($context, $cuentaProveedor);
        $cuentaBanco = $data['cuenta_contable'] ?? null;
        $cuentaBancoNombre = $this->getCuentaContableNombre($context, $cuentaBanco);
        $documento = $data['documento'] ?? ($data['numero_cheque'] ?? ($facturas[0]['numero'] ?? ''));
        $centroCosto = $tipoEgreso === 'cuenta_bancaria' ? ($data['centro_costo'] ?? null) : null;
        $centroActividad = $tipoEgreso === 'cuenta_bancaria' ? ($data['centro_actividad'] ?? null) : null;

        $diario = [];
        $existingDiario = $this->diarioEntries[$providerKey] ?? [];
        $fila = count($existingDiario) + 1;

        $montosFactura = $this->calculateMontos($totalPago, 0, $moneda, $monedaBase, $cotizacion, $cotizacionExterna);
        $montosPago = $this->calculateMontos(0, $totalPago, $moneda, $monedaBase, $cotizacion, $cotizacionExterna);

        $diario[] = [
            'fila' => $fila++,
            'cuenta' => $cuentaProveedor,
            'cuenta_nombre' => $cuentaProveedorNombre,
            'documento' => $documento,
            'cotizacion' => $cotizacion,
            'debito' => $montosFactura['debito_local'],
            'credito' => $montosFactura['credito_local'],
            'debito_extranjera' => $montosFactura['debito_extranjera'],
            'credito_extranjera' => $montosFactura['credito_extranjera'],
            'beneficiario' => $record->proveedor_nombre ?? null,
            'cuenta_bancaria' => $data['cuenta_bancaria'] ?? null,
            'banco_cheque' => $tipoEgreso === 'cuenta_bancaria' ? ($data['documento'] ?? null) : ($data['numero_cheque'] ?? null),
            'fecha_vencimiento' => $data['fecha_cheque'] ?? null,
            'formato_cheque' => $data['formato_cheque'] ?? null,
            'codigo_contable' => $cuentaProveedor,
            'detalle' => $detalle,
            'centro_costo' => $centroCosto,
            'centro_actividad' => $centroActividad,
            'directorio' => $documento,
        ];

        $diario[] = [
            'fila' => $fila++,
            'cuenta' => $cuentaBanco,
            'cuenta_nombre' => $cuentaBancoNombre,
            'documento' => $documento,
            'cotizacion' => $cotizacion,
            'debito' => $montosPago['debito_local'],
            'credito' => $montosPago['credito_local'],
            'debito_extranjera' => $montosPago['debito_extranjera'],
            'credito_extranjera' => $montosPago['credito_extranjera'],
            'beneficiario' => $record->proveedor_nombre ?? null,
            'cuenta_bancaria' => $data['cuenta_bancaria'] ?? null,
            'banco_cheque' => $tipoEgreso === 'cuenta_bancaria' ? ($data['documento'] ?? null) : ($data['numero_cheque'] ?? null),
            'fecha_vencimiento' => $data['fecha_cheque'] ?? null,
            'formato_cheque' => $data['formato_cheque'] ?? null,
            'codigo_contable' => $cuentaBanco,
            'detalle' => $tipoEgreso === 'cuenta_bancaria'
                ? $detalle
                : ('Pago bancario ' . ($data['cuenta_bancaria'] ?? '')),
            'centro_costo' => $centroCosto,
            'centro_actividad' => $centroActividad,
            'directorio' => $documento,
        ];

        $existingDirectorio = $this->directorioEntries[$providerKey] ?? [];
        $this->directorioEntries[$providerKey] = array_merge($existingDirectorio, $directorio);
        $this->diarioEntries[$providerKey] = array_merge($existingDiario, $diario);
        $this->paymentMappings[$providerKey][] = [
            'tipo_egreso' => $tipoEgreso,
            'moneda' => $moneda,
            'formato' => $data['formato'] ?? null,
            'detalle' => $detalle,
            'cotizacion' => $cotizacion,
            'cotizacion_externa' => $cotizacionExterna,
            'cuenta_bancaria' => $data['cuenta_bancaria'] ?? null,
            'cuenta_contable' => $cuentaBanco,
            'numero_cheque' => $data['numero_cheque'] ?? null,
            'formato_cheque' => $data['formato_cheque'] ?? null,
            'fecha_cheque' => $data['fecha_cheque'] ?? null,
            'moneda_base' => $monedaBase,
            'valor' => $valor,
            'documento' => $data['documento'] ?? null,
            'centro_costo' => $data['centro_costo'] ?? null,
            'centro_actividad' => $data['centro_actividad'] ?? null,
        ];

        $this->syncFacturaDisplayFromPago(
            $providerKey,
            array_merge($data, [
                'detalle' => $detalle,
                'moneda' => $moneda,
                'cotizacion' => $cotizacion,
                'cotizacion_externa' => $cotizacionExterna,
            ]),
            $monedaBase,
            $facturas
        );

        return true;
    }

    protected function syncFacturaDisplayFromPago(string $providerKey, array $data, ?string $monedaBase, array $pagos): void
    {
        $cotizacion = (float) ($data['cotizacion'] ?? 1);
        $cotizacionExterna = (float) ($data['cotizacion_externa'] ?? 1);
        $moneda = $data['moneda'] ?? $monedaBase;
        $pagosMap = collect($pagos)
            ->keyBy(fn(array $factura) => $this->buildFacturaKey($factura))
            ->all();

        $this->facturasByProvider[$providerKey] = collect($this->facturasByProvider[$providerKey] ?? [])
            ->map(function (array $factura) use ($data, $moneda, $monedaBase, $cotizacion, $cotizacionExterna, $pagosMap) {
                $facturaKey = $this->buildFacturaKey($factura);
                $pago = $pagosMap[$facturaKey] ?? null;
                $abonoPagado = (float) ($factura['abono_pagado'] ?? 0);
                $abonoTotal = (float) ($factura['abono_total'] ?? $factura['abono'] ?? 0);

                if ($pago) {
                    $abonoPagado += (float) ($pago['abono_pago'] ?? 0);
                }

                $saldoPendiente = max(0, $abonoTotal - $abonoPagado);
                $montos = $this->calculateMontos($saldoPendiente, 0, $moneda, $monedaBase, $cotizacion, $cotizacionExterna);

                return array_merge($factura, [
                    'detalle' => $data['detalle'] ?? $factura['detalle'] ?? null,
                    'moneda' => $moneda,
                    'cotizacion' => $cotizacion,
                    'debito_local' => $montos['debito_local'],
                    'credito_local' => $montos['credito_local'],
                    'debito_extranjera' => $montos['debito_extranjera'],
                    'credito_extranjera' => $montos['credito_extranjera'],
                    'abono_pagado' => $abonoPagado,
                    'saldo_pendiente' => $saldoPendiente,
                ]);
            })
            ->values()
            ->all();
    }

    protected function getDirectorioFormSchema(SolicitudPagoDetalle $record): array
    {
        $context = $this->resolveProviderContext($record);
        $monedas = $this->getMonedasOptions($context);
        $formatos = $this->getFormatosOptions($context);
        $cuentas = $this->getCuentasBancariasOptions($context);
        $cuentasContables = $this->getCuentasContablesOptions($context);
        $centrosCosto = $this->getCentrosCostoOptions($context);
        $centrosActividad = $this->getCentrosActividadOptions($context);

        $monedaBase = $this->getMonedaBase($context);
        $cotizacionExterna = $this->getCotizacionExterna($context);

        return [
            Wizard::make([
                Step::make('Tipo de egreso')
                    ->schema([
                        Select::make('tipo_egreso')
                            ->label('Tipo de egreso')
                            ->options([
                                'cheque' => 'Cheque',
                                'cuenta_bancaria' => 'Cuenta bancaria',
                            ])
                            ->default('cheque')
                            ->required()
                            ->reactive(),
                    ]),
                Step::make('Datos contables')
                    ->schema([
                        Select::make('moneda')
                            ->label('Moneda')
                            ->options($monedas)
                            ->searchable()
                            ->required()
                            ->default($monedaBase),
                        Select::make('formato')
                            ->label('Formato')
                            ->options($formatos)
                            ->searchable()
                            ->required(),
                        Textarea::make('detalle')
                            ->label('Detalle')
                            ->rows(2)
                            ->default('Egreso de solicitud #' . $this->record->getKey())
                            ->required(),
                        TextInput::make('valor')
                            ->label('Valor')
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(fn() => $this->getProviderSaldoPendiente($record))
                            ->required(),
                        TextInput::make('cotizacion')
                            ->label('Cotización')
                            ->numeric()
                            ->default(1)
                            ->required(),
                        TextInput::make('cotizacion_externa')
                            ->label('Cotización externa')
                            ->numeric()
                            ->default($cotizacionExterna)
                            ->required(),
                    ])
                    ->columns(2)
                    ->visible(fn(Get $get) => $get('tipo_egreso') === 'cheque'),
                Step::make('Cuenta bancaria')
                    ->schema([
                        Select::make('cuenta_bancaria')
                            ->label('Cuenta bancaria')
                            ->options($cuentas)
                            ->searchable()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set, Get $get) use ($context): void {
                                $info = $this->getCuentaBancariaInfo($context, $state);
                                if ($info) {
                                    $set('cuenta_contable', $info['cuenta_contable']);
                                    if ($get('tipo_egreso') === 'cheque') {
                                        $set('numero_cheque', $info['numero_cheque']);
                                        $set('formato_cheque', $info['formato_cheque']);
                                    }
                                }
                            }),
                        TextInput::make('numero_cheque')
                            ->label('N° cheque')
                            ->maxLength(50)
                            ->required(fn(Get $get) => $get('tipo_egreso') === 'cheque')
                            ->visible(fn(Get $get) => $get('tipo_egreso') === 'cheque'),
                        Select::make('formato_cheque')
                            ->label('Formato cheque')
                            ->options($formatos)
                            ->searchable()
                            ->required(fn(Get $get) => $get('tipo_egreso') === 'cheque')
                            ->visible(fn(Get $get) => $get('tipo_egreso') === 'cheque'),
                        DatePicker::make('fecha_cheque')
                            ->label('Fecha de cheque')
                            ->default(Carbon::now())
                            ->required(fn(Get $get) => $get('tipo_egreso') === 'cheque')
                            ->visible(fn(Get $get) => $get('tipo_egreso') === 'cheque'),
                        Select::make('cuenta_contable')
                            ->label('Cuenta contable')
                            ->options($cuentasContables)
                            ->searchable()
                            ->required(fn(Get $get) => $get('tipo_egreso') === 'cheque')
                            ->visible(fn(Get $get) => $get('tipo_egreso') === 'cheque'),
                        TextInput::make('documento')
                            ->label('Documento')
                            ->maxLength(50)
                            ->required(fn(Get $get) => $get('tipo_egreso') === 'cuenta_bancaria')
                            ->visible(fn(Get $get) => $get('tipo_egreso') === 'cuenta_bancaria'),
                        Textarea::make('detalle_cuenta')
                            ->label('Detalle')
                            ->rows(2)
                            ->default('Egreso de solicitud #' . $this->record->getKey())
                            ->required(fn(Get $get) => $get('tipo_egreso') === 'cuenta_bancaria')
                            ->visible(fn(Get $get) => $get('tipo_egreso') === 'cuenta_bancaria'),
                        Select::make('centro_costo')
                            ->label('Centro de costo')
                            ->options($centrosCosto)
                            ->searchable()
                            ->required(fn(Get $get) => $get('tipo_egreso') === 'cuenta_bancaria')
                            ->visible(fn(Get $get) => $get('tipo_egreso') === 'cuenta_bancaria'),
                        Select::make('centro_actividad')
                            ->label('Centro de actividad')
                            ->options($centrosActividad)
                            ->searchable()
                            ->required(fn(Get $get) => $get('tipo_egreso') === 'cuenta_bancaria')
                            ->visible(fn(Get $get) => $get('tipo_egreso') === 'cuenta_bancaria'),
                        TextInput::make('valor_cuenta')
                            ->label('Valor')
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(fn() => $this->getProviderSaldoPendiente($record))
                            ->required(fn(Get $get) => $get('tipo_egreso') === 'cuenta_bancaria')
                            ->visible(fn(Get $get) => $get('tipo_egreso') === 'cuenta_bancaria'),
                    ])
                    ->columns(2),
            ])
                ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                    <x-filament::button type="submit" size="sm">
                        Generar
                    </x-filament::button>
                BLADE)))
                ->skippable(false),
        ];
    }

    protected function getProviderSaldoPendiente(SolicitudPagoDetalle $record): float
    {
        $key = $this->buildProviderKeyFromValues($record->proveedor_codigo, $record->proveedor_ruc);

        return (float) collect($this->facturasByProvider[$key] ?? [])
            ->sum(fn(array $factura) => (float) ($factura['saldo_pendiente'] ?? 0));
    }

    protected function distributePagoFacturas(array $facturas, float $valor): array
    {
        $restante = $valor;
        $result = [];

        foreach ($facturas as $factura) {
            if ($restante <= 0) {
                break;
            }

            $pendiente = (float) ($factura['saldo_pendiente'] ?? 0);

            if ($pendiente <= 0) {
                continue;
            }

            $aplicado = min($pendiente, $restante);

            $result[] = array_merge($factura, [
                'abono_pago' => $aplicado,
            ]);

            $restante -= $aplicado;
        }

        return $result;
    }

    protected function buildFacturaKey(array $factura): string
    {
        return implode('|', [
            (string) ($factura['numero'] ?? ''),
            (string) ($factura['fecha_emision'] ?? ''),
            (string) ($factura['fecha_vencimiento'] ?? ''),
        ]);
    }

    protected function resolveProviderContext(SolicitudPagoDetalle $record): array
    {
        $key = $this->buildProviderKeyFromValues($record->proveedor_codigo, $record->proveedor_ruc);

        return $this->providerContexts[$key] ?? [
            'conexion' => (int) ($record->erp_conexion ?? 0),
            'empresa' => $record->erp_empresa_id,
            'sucursal' => $record->erp_sucursal,
        ];
    }

    protected function getCatalogCacheKey(array $context, string $type): string
    {
        return implode('|', [
            $type,
            $context['conexion'] ?? '0',
            $context['empresa'] ?? '0',
            $context['sucursal'] ?? '0',
        ]);
    }

    protected function getExternalConnection(array $context): ?string
    {
        $conexionId = (int) ($context['conexion'] ?? 0);

        if (! $conexionId) {
            return null;
        }

        return SolicitudPagoResource::getExternalConnectionName($conexionId);
    }

    protected function getMonedasOptions(array $context): array
    {
        $cacheKey = $this->getCatalogCacheKey($context, 'monedas');

        if (array_key_exists($cacheKey, $this->catalogCache)) {
            return $this->catalogCache[$cacheKey];
        }

        $connection = $this->getExternalConnection($context);
        $empresa = $context['empresa'] ?? null;

        if (! $connection || ! $empresa) {
            return $this->catalogCache[$cacheKey] = [];
        }

        try {
            $options = DB::connection($connection)
                ->table('saemone')
                ->where('mone_cod_empr', $empresa)
                ->pluck('mone_des_mone', 'mone_cod_mone')
                ->all();
        } catch (\Throwable $e) {
            $options = [];
        }

        return $this->catalogCache[$cacheKey] = $options;
    }

    protected function getFormatosOptions(array $context): array
    {
        $cacheKey = $this->getCatalogCacheKey($context, 'formatos');

        if (array_key_exists($cacheKey, $this->catalogCache)) {
            return $this->catalogCache[$cacheKey];
        }

        $connection = $this->getExternalConnection($context);
        $empresa = $context['empresa'] ?? null;

        if (! $connection || ! $empresa) {
            return $this->catalogCache[$cacheKey] = [];
        }

        try {
            $options = DB::connection($connection)
                ->table('saeftrn')
                ->where('ftrn_cod_empr', $empresa)
                ->where('ftrn_cod_modu', 5)
                ->where('ftrn_tip_movi', 'EG')
                ->pluck('ftrn_des_ftrn', 'ftrn_cod_ftrn')
                ->all();
        } catch (\Throwable $e) {
            $options = [];
        }

        return $this->catalogCache[$cacheKey] = $options;
    }

    protected function getCuentasBancariasOptions(array $context): array
    {
        $cacheKey = $this->getCatalogCacheKey($context, 'cuentas-bancarias');

        if (array_key_exists($cacheKey, $this->catalogCache)) {
            return $this->catalogCache[$cacheKey];
        }

        $connection = $this->getExternalConnection($context);
        $empresa = $context['empresa'] ?? null;

        if (! $connection || ! $empresa) {
            return $this->catalogCache[$cacheKey] = [];
        }

        try {
            $rows = DB::connection($connection)
                ->table('saectab')
                ->join('saebanc', function ($join) {
                    $join->on('banc_cod_empr', '=', 'ctab_cod_empr')
                        ->on('banc_cod_banc', '=', 'ctab_cod_banc');
                })
                ->where('ctab_cod_empr', $empresa)
                ->where('ctab_tip_ctab', 'C')
                ->select([
                    'ctab_cod_ctab',
                    'ctab_cod_cuen',
                    'banc_nom_banc',
                    'ctab_num_ctab',
                ])
                ->orderBy('banc_nom_banc')
                ->get();

            $options = $rows
                ->mapWithKeys(fn($row) => [
                    $row->ctab_cod_ctab => $row->ctab_cod_cuen . ' - ' . $row->banc_nom_banc . ' - ' . $row->ctab_num_ctab,
                ])
                ->all();
        } catch (\Throwable $e) {
            $options = [];
        }

        return $this->catalogCache[$cacheKey] = $options;
    }

    protected function getCuentasContablesOptions(array $context): array
    {
        $cacheKey = $this->getCatalogCacheKey($context, 'cuentas-contables');

        if (array_key_exists($cacheKey, $this->catalogCache)) {
            return $this->catalogCache[$cacheKey];
        }

        $connection = $this->getExternalConnection($context);
        $empresa = $context['empresa'] ?? null;

        if (! $connection || ! $empresa) {
            return $this->catalogCache[$cacheKey] = [];
        }

        try {
            $options = DB::connection($connection)
                ->table('saecuen')
                ->where('cuen_cod_empr', $empresa)
                ->pluck('cuen_nom_cuen', 'cuen_cod_cuen')
                ->all();
        } catch (\Throwable $e) {
            $options = [];
        }

        return $this->catalogCache[$cacheKey] = $options;
    }

    protected function getCentrosCostoOptions(array $context): array
    {
        $cacheKey = $this->getCatalogCacheKey($context, 'centros-costo');

        if (array_key_exists($cacheKey, $this->catalogCache)) {
            return $this->catalogCache[$cacheKey];
        }

        $connection = $this->getExternalConnection($context);
        $empresa = $context['empresa'] ?? null;

        if (! $connection || ! $empresa) {
            return $this->catalogCache[$cacheKey] = [];
        }

        try {
            $rows = DB::connection($connection)
                ->table('saeccosn')
                ->where('ccosn_cod_empr', $empresa)
                ->select(['ccosn_cod_ccosn', 'ccosn_nom_ccosn'])
                ->orderBy('ccosn_nom_ccosn')
                ->get();

            $options = $rows
                ->mapWithKeys(fn($row) => [
                    $row->ccosn_cod_ccosn => $row->ccosn_nom_ccosn . ' - ' . $row->ccosn_cod_ccosn,
                ])
                ->all();
        } catch (\Throwable $e) {
            $options = [];
        }

        return $this->catalogCache[$cacheKey] = $options;
    }

    protected function getCentrosActividadOptions(array $context): array
    {
        $cacheKey = $this->getCatalogCacheKey($context, 'centros-actividad');

        if (array_key_exists($cacheKey, $this->catalogCache)) {
            return $this->catalogCache[$cacheKey];
        }

        $connection = $this->getExternalConnection($context);
        $empresa = $context['empresa'] ?? null;

        if (! $connection || ! $empresa) {
            return $this->catalogCache[$cacheKey] = [];
        }

        try {
            $rows = DB::connection($connection)
                ->table('saecact')
                ->where('cact_cod_empr', $empresa)
                ->select(['cact_cod_cact', 'cact_nom_cact'])
                ->orderBy('cact_nom_cact')
                ->get();

            $options = $rows
                ->mapWithKeys(fn($row) => [
                    $row->cact_cod_cact => $row->cact_nom_cact . ' - ' . $row->cact_cod_cact,
                ])
                ->all();
        } catch (\Throwable $e) {
            $options = [];
        }

        return $this->catalogCache[$cacheKey] = $options;
    }

    protected function getCuentaBancariaInfo(array $context, $cta): ?array
    {
        if (! $cta) {
            return null;
        }

        $cacheKey = $this->getCatalogCacheKey($context, 'cuenta-info-' . $cta);

        if (array_key_exists($cacheKey, $this->catalogCache)) {
            return $this->catalogCache[$cacheKey];
        }

        $connection = $this->getExternalConnection($context);
        $empresa = $context['empresa'] ?? null;

        if (! $connection || ! $empresa) {
            return $this->catalogCache[$cacheKey] = null;
        }

        try {
            $row = DB::connection($connection)
                ->table('saectab')
                ->where('ctab_cod_empr', $empresa)
                ->where('ctab_cod_ctab', $cta)
                ->select(['ctab_num_cheq', 'ctab_for_cheq', 'ctab_cod_cuen'])
                ->first();

            if (! $row) {
                return $this->catalogCache[$cacheKey] = null;
            }

            return $this->catalogCache[$cacheKey] = [
                'numero_cheque' => (string) $row->ctab_num_cheq,
                'formato_cheque' => $row->ctab_for_cheq,
                'cuenta_contable' => $row->ctab_cod_cuen,
            ];
        } catch (\Throwable $e) {
            return $this->catalogCache[$cacheKey] = null;
        }
    }

    protected function getCuentaProveedor(array $context, ?string $proveedorCodigo): ?string
    {
        if (! $proveedorCodigo) {
            return null;
        }

        $cacheKey = $this->getCatalogCacheKey($context, 'cuenta-proveedor-' . $proveedorCodigo);

        if (array_key_exists($cacheKey, $this->catalogCache)) {
            return $this->catalogCache[$cacheKey];
        }

        $connection = $this->getExternalConnection($context);
        $empresa = $context['empresa'] ?? null;

        if (! $connection || ! $empresa) {
            return $this->catalogCache[$cacheKey] = null;
        }

        try {
            $cuenta = DB::connection($connection)
                ->table('saeclpv')
                ->where('clpv_cod_empr', $empresa)
                ->where('clpv_cod_clpv', $proveedorCodigo)
                ->value('clpv_cod_cuen');
        } catch (\Throwable $e) {
            $cuenta = null;
        }

        return $this->catalogCache[$cacheKey] = $cuenta;
    }

    protected function getCuentaContableNombre(array $context, ?string $cuenta): ?string
    {
        if (! $cuenta) {
            return null;
        }

        $cacheKey = $this->getCatalogCacheKey($context, 'cuenta-nombre-' . $cuenta);

        if (array_key_exists($cacheKey, $this->catalogCache)) {
            return $this->catalogCache[$cacheKey];
        }

        $connection = $this->getExternalConnection($context);
        $empresa = $context['empresa'] ?? null;

        if (! $connection || ! $empresa) {
            return $this->catalogCache[$cacheKey] = null;
        }

        try {
            $nombre = DB::connection($connection)
                ->table('saecuen')
                ->where('cuen_cod_empr', $empresa)
                ->where('cuen_cod_cuen', $cuenta)
                ->value('cuen_nom_cuen');
        } catch (\Throwable $e) {
            $nombre = null;
        }

        return $this->catalogCache[$cacheKey] = $nombre;
    }

    protected function getMonedaBase(array $context): ?string
    {
        $cacheKey = $this->getCatalogCacheKey($context, 'moneda-base');

        if (array_key_exists($cacheKey, $this->catalogCache)) {
            return $this->catalogCache[$cacheKey];
        }

        $connection = $this->getExternalConnection($context);
        $empresa = $context['empresa'] ?? null;

        if (! $connection || ! $empresa) {
            return $this->catalogCache[$cacheKey] = null;
        }

        try {
            $moneda = DB::connection($connection)
                ->table('saepcon')
                ->where('pcon_cod_empr', $empresa)
                ->value('pcon_mon_base');
        } catch (\Throwable $e) {
            $moneda = null;
        }

        return $this->catalogCache[$cacheKey] = $moneda;
    }

    protected function getCotizacionExterna(array $context): float
    {
        $cacheKey = $this->getCatalogCacheKey($context, 'cotizacion-externa');

        if (array_key_exists($cacheKey, $this->catalogCache)) {
            return $this->catalogCache[$cacheKey];
        }

        $connection = $this->getExternalConnection($context);
        $empresa = $context['empresa'] ?? null;

        if (! $connection || ! $empresa) {
            return $this->catalogCache[$cacheKey] = 1.0;
        }

        try {
            $monedaExtra = DB::connection($connection)
                ->table('saepcon')
                ->where('pcon_cod_empr', $empresa)
                ->value('pcon_seg_mone');

            if (! $monedaExtra) {
                return $this->catalogCache[$cacheKey] = 1.0;
            }

            $cotizacion = DB::connection($connection)
                ->table('saetcam')
                ->where('mone_cod_empr', $empresa)
                ->where('tcam_cod_mone', $monedaExtra)
                ->orderByDesc('tcam_fec_tcam')
                ->value('tcam_val_tcam');
        } catch (\Throwable $e) {
            $cotizacion = null;
        }

        return $this->catalogCache[$cacheKey] = (float) ($cotizacion ?? 1.0);
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('volver')
                ->label('Volver al listado')
                ->color('gray')
                ->icon('heroicon-o-arrow-left')
                ->url(EgresoSolicitudPagoResource::getUrl()),
            Action::make('registrarEgreso')
                ->label('Registrar egreso')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->disabled(fn() => $this->totalDiferencia !== 0.0 || empty($this->diarioEntries))
                ->action(function (): void {
                    Notification::make()
                        ->title('Egreso registrado')
                        ->body('El egreso quedó listo para su registro contable.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getTotalDebitoProperty(): float
    {
        return collect($this->diarioEntries)
            ->flatten(1)
            ->sum(fn(array $linea) => (float) ($linea['debito'] ?? 0));
    }

    public function getTotalCreditoProperty(): float
    {
        return collect($this->diarioEntries)
            ->flatten(1)
            ->sum(fn(array $linea) => (float) ($linea['credito'] ?? 0));
    }

    public function getTotalDiferenciaProperty(): float
    {
        return round($this->totalDebito - $this->totalCredito, 2);
    }

    public function getTotalAbonoProperty(): float
    {
        return (float) ($this->record->detalles?->sum('abono_aplicado') ?? 0);
    }

    public function getTotalFacturasProperty(): int
    {
        return (int) ($this->record->detalles?->count() ?? 0);
    }

    public function getTotalSaldoProperty(): float
    {
        return (float) ($this->record->detalles?->sum('saldo_al_crear') ?? 0);
    }

    public function getTotalAbonoHtmlProperty(): HtmlString
    {
        return new HtmlString('$' . number_format($this->totalAbono, 2, '.', ','));
    }
}
