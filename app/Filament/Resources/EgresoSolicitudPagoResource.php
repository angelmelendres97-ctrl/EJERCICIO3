<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EgresoSolicitudPagoResource\Pages;
use App\Models\SolicitudPago;
use App\Services\SolicitudPagoReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class EgresoSolicitudPagoResource extends Resource
{
    protected static ?string $model = SolicitudPago::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Egresos';

    protected static ?string $modelLabel = 'Solicitud aprobada';

    protected static ?string $pluralModelLabel = 'Solicitudes aprobadas';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereRaw('upper(estado) = ?', ['APROBADA']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(null)
            ->columns([
                TextColumn::make('empresa.nombre_empresa')
                    ->label('Conexión')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creadoPor.name')
                    ->label('Creado por')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->limit(40),
                TextColumn::make('monto_utilizado')
                    ->label('Abono a pagar')
                    ->money('USD')
                    ->sortable(),
                TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(function (string $state): string {
                        return strtoupper($state) === 'APROBADA'
                            ? 'Aprobada y pendiente de egreso'
                            : $state;
                    })
                    ->color(fn(string $state) => strtoupper($state) === 'APROBADA' ? 'warning' : 'success')
                    ->label('Estado'),
            ])
            ->actions([
                Tables\Actions\Action::make('registrarEgreso')
                    ->label('Registrar egreso')
                    ->icon('heroicon-o-arrow-up-right')
                    ->color('primary')
                    ->url(fn(SolicitudPago $record) => self::getUrl('registrar', ['record' => $record]))
                    ->openUrlInNewTab(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('solicitudPdf')
                        ->label('Solicitud PDF')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('primary')
                        ->action(function (SolicitudPago $record) {
                            $record->loadMissing('detalles');
                            $data = SolicitudPagoReportService::buildReportData($record);
                            $descripcion = $record->motivo ?? 'Solicitud de pago de facturas';

                            return response()->streamDownload(function () use ($data, $descripcion) {
                                echo Pdf::loadView('pdfs.solicitud-pago-facturas-general', [
                                    'empresas' => $data['empresas'],
                                    'resumenEmpresas' => $data['resumen'],
                                    'usuario' => Auth::user()?->name,
                                    'totales' => $data['totales'],
                                    'descripcionReporte' => $descripcion,
                                    'compras' => $data['comprasReport'],
                                ])->setPaper('a4', 'landscape')->stream();
                            }, 'solicitud-pago-facturas.pdf');
                        }),
                    Tables\Actions\Action::make('solicitudExcel')
                        ->label('Solicitud EXCEL')
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->action(function (SolicitudPago $record) {
                            $record->loadMissing('detalles');
                            $data = SolicitudPagoReportService::buildReportData($record);
                            $rows = SolicitudPagoReportService::buildGeneralExcelRows($data['empresas']);

                            return response()->streamDownload(function () use ($rows) {
                                $handle = fopen('php://output', 'wb');

                                fputcsv($handle, array_keys($rows[0] ?? [
                                    'Conexion' => 'Conexion',
                                    'Empresa' => 'Empresa',
                                    'Proveedor' => 'Proveedor',
                                    'RUC' => 'RUC',
                                    'Descripcion' => 'Descripcion',
                                    'Valor' => 'Valor',
                                    'Abono' => 'Abono',
                                    'Saldo pendiente' => 'Saldo pendiente',
                                ]));

                                foreach ($rows as $row) {
                                    fputcsv($handle, $row);
                                }

                                fclose($handle);
                            }, 'solicitud-pago-facturas.csv');
                        }),
                ])
                    ->label('Descargar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->button(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEgresoSolicitudPagos::route('/'),
            'registrar' => Pages\RegistrarEgreso::route('/{record}/registro'),
        ];
    }
}
