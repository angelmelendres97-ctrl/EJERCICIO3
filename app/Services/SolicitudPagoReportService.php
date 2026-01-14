<?php

namespace App\Services;

use App\Filament\Resources\SolicitudPagoResource;
use App\Models\Empresa;
use App\Models\SolicitudPago;

class SolicitudPagoReportService
{
    public static function buildReportData(SolicitudPago $solicitud): array
    {
        $facturas = self::buildFacturasFromSolicitud($solicitud);
        [$facturasNormales, $compras] = self::splitFacturas($facturas);

        $proveedores = self::buildSelectedProvidersWithMetadata($facturasNormales);
        $empresas = self::buildEmpresasParaReportes($proveedores);
        $resumen = self::buildResumenPorEmpresaDesdeFacturas(array_merge($facturasNormales, $compras));
        $totales = self::buildTotalesDesdeFacturas(array_merge($facturasNormales, $compras));
        $comprasReport = self::buildComprasReportRows($compras);

        return [
            'facturas' => $facturasNormales,
            'compras' => $compras,
            'empresas' => $empresas,
            'resumen' => $resumen,
            'totales' => $totales,
            'comprasReport' => $comprasReport,
        ];
    }

    public static function buildGeneralExcelRows(array $empresas): array
    {
        return collect($empresas)
            ->flatMap(function (array $empresa) {
                return collect($empresa['proveedores'] ?? [])->map(function (array $proveedor) use ($empresa) {
                    return [
                        'Conexion' => $empresa['conexion_nombre'] ?? '',
                        'Empresa' => $empresa['empresa_nombre'] ?? $empresa['empresa_codigo'],
                        'Proveedor' => $proveedor['nombre'] ?? '',
                        'RUC' => $proveedor['ruc'] ?? '',
                        'Descripcion' => $proveedor['descripcion'] ?? '',
                        'Valor' => number_format((float) ($proveedor['totales']['valor'] ?? 0), 2, '.', ''),
                        'Abono' => number_format((float) ($proveedor['totales']['abono'] ?? 0), 2, '.', ''),
                        'Saldo pendiente' => number_format((float) ($proveedor['totales']['saldo'] ?? 0), 2, '.', ''),
                    ];
                });
            })
            ->values()
            ->all();
    }

    private static function buildFacturasFromSolicitud(SolicitudPago $solicitud): array
    {
        $registros = collect();
        $conexionNombres = [];
        $empresaOptionsCache = [];
        $sucursalOptionsCache = [];

        foreach ($solicitud->detalles as $detalle) {
            $conexionId = (int) ($detalle->erp_conexion ?? $solicitud->id_empresa);
            $empresaCodigo = (string) ($detalle->erp_empresa_id ?? '');
            $sucursalCodigo = (string) ($detalle->erp_sucursal ?? '');
            $numeroFactura = (string) ($detalle->numero_factura ?? '');
            $esCompra = strtoupper((string) $detalle->erp_tabla) === 'COMPRA' || str_starts_with($numeroFactura, 'COMPRA-');

            if (! isset($conexionNombres[$conexionId])) {
                $conexionNombres[$conexionId] = Empresa::query()
                    ->where('id', $conexionId)
                    ->value('nombre_empresa') ?? (string) $conexionId;
            }

            if (! isset($empresaOptionsCache[$conexionId])) {
                $empresaOptionsCache[$conexionId] = SolicitudPagoResource::getEmpresasOptions($conexionId);
            }

            $empresaOptions = $empresaOptionsCache[$conexionId];

            if (! isset($sucursalOptionsCache[$conexionId][$empresaCodigo])) {
                $sucursalOptionsCache[$conexionId][$empresaCodigo] = SolicitudPagoResource::getSucursalesOptions(
                    $conexionId,
                    array_filter([$empresaCodigo])
                );
            }

            $sucursalOptions = $sucursalOptionsCache[$conexionId][$empresaCodigo] ?? [];

            $proveedorNombre = $detalle->proveedor_nombre ?? ($detalle->proveedor_codigo ?? '');

            $registros->push([
                'proveedor_key' => self::buildProveedorKey(
                    $detalle->proveedor_codigo ?? '',
                    $detalle->proveedor_ruc ?? '',
                    $proveedorNombre
                ),
                'conexion_id' => $conexionId,
                'conexion_nombre' => $conexionNombres[$conexionId],
                'empresa_codigo' => $empresaCodigo,
                'empresa_nombre' => $empresaOptions[$empresaCodigo] ?? $empresaCodigo,
                'sucursal_codigo' => $sucursalCodigo,
                'sucursal_nombre' => $sucursalOptions[$sucursalCodigo] ?? $sucursalCodigo,
                'proveedor_codigo' => $detalle->proveedor_codigo ?? '',
                'proveedor_nombre' => $proveedorNombre,
                'proveedor_ruc' => $detalle->proveedor_ruc,
                'numero' => $numeroFactura,
                'fecha_emision' => $detalle->fecha_emision,
                'fecha_vencimiento' => $detalle->fecha_vencimiento,
                'total' => (float) ($detalle->monto_factura ?? 0),
                'saldo' => (float) ($detalle->saldo_al_crear ?? 0),
                'abono' => (float) ($detalle->abono_aplicado ?? 0),
                'descripcion' => $proveedorNombre,
                'tipo' => $esCompra ? 'compra' : null,
            ]);
        }

        return $registros->values()->all();
    }

    private static function splitFacturas(array $facturas): array
    {
        $normales = [];
        $compras = [];

        foreach ($facturas as $factura) {
            if (self::isCompraFactura($factura)) {
                $compras[] = $factura;
            } else {
                $normales[] = $factura;
            }
        }

        return [$normales, $compras];
    }

    private static function isCompraFactura(array $factura): bool
    {
        if (($factura['tipo'] ?? null) === 'compra') {
            return true;
        }

        $numero = (string) ($factura['numero'] ?? '');

        return str_starts_with($numero, 'COMPRA-');
    }

    private static function buildSelectedProvidersWithMetadata(array $facturas): array
    {
        $proveedores = [];

        foreach ($facturas as $factura) {
            $providerKey = $factura['proveedor_key'] ?? null;

            if (! $providerKey) {
                continue;
            }

            $valor = (float) ($factura['total'] ?? $factura['monto'] ?? $factura['saldo'] ?? 0);
            $abono = (float) ($factura['abono'] ?? 0);
            $saldo = (float) ($factura['saldo_pendiente'] ?? max(0, ($factura['saldo'] ?? $valor) - $abono));

            if (! isset($proveedores[$providerKey])) {
                $proveedores[$providerKey] = [
                    'key' => $providerKey,
                    'proveedor_codigo' => $factura['proveedor_codigo'] ?? null,
                    'proveedor_nombre' => $factura['proveedor_nombre'] ?? null,
                    'proveedor_ruc' => $factura['proveedor_ruc'] ?? null,
                    'proveedor_actividad' => $factura['proveedor_actividad'] ?? null,
                    'descripcion' => $factura['descripcion'] ?? ($factura['proveedor_nombre'] ?? ''),
                    'totales' => [
                        'valor' => 0,
                        'abono' => 0,
                        'saldo' => 0,
                    ],
                    'empresas' => [],
                ];
            }

            $empresaKey = ($factura['conexion_id'] ?? '') . '|' . ($factura['empresa_codigo'] ?? '');
            $sucursalKey = $empresaKey . '|' . ($factura['sucursal_codigo'] ?? '');

            if (! isset($proveedores[$providerKey]['empresas'][$empresaKey])) {
                $proveedores[$providerKey]['empresas'][$empresaKey] = [
                    'conexion_id' => $factura['conexion_id'] ?? null,
                    'conexion_nombre' => $factura['conexion_nombre'] ?? '',
                    'empresa_codigo' => $factura['empresa_codigo'] ?? null,
                    'empresa_nombre' => $factura['empresa_nombre'] ?? null,
                    'totales' => [
                        'valor' => 0,
                        'abono' => 0,
                        'saldo' => 0,
                    ],
                    'sucursales' => [],
                ];
            }

            if (! isset($proveedores[$providerKey]['empresas'][$empresaKey]['sucursales'][$sucursalKey])) {
                $proveedores[$providerKey]['empresas'][$empresaKey]['sucursales'][$sucursalKey] = [
                    'sucursal_codigo' => $factura['sucursal_codigo'] ?? null,
                    'sucursal_nombre' => $factura['sucursal_nombre'] ?? null,
                    'totales' => [
                        'valor' => 0,
                        'abono' => 0,
                        'saldo' => 0,
                    ],
                    'facturas' => [],
                ];
            }

            $proveedores[$providerKey]['empresas'][$empresaKey]['sucursales'][$sucursalKey]['facturas'][] = [
                'numero' => $factura['numero'] ?? '',
                'fecha_emision' => $factura['fecha_emision'] ?? '',
                'fecha_vencimiento' => $factura['fecha_vencimiento'] ?? '',
                'valor' => $valor,
                'abono' => $abono,
                'saldo' => $saldo,
                'sucursal_nombre' => $factura['sucursal_nombre'] ?? '',
            ];

            $proveedores[$providerKey]['totales']['valor'] += $valor;
            $proveedores[$providerKey]['totales']['abono'] += $abono;
            $proveedores[$providerKey]['totales']['saldo'] += $saldo;

            $proveedores[$providerKey]['empresas'][$empresaKey]['totales']['valor'] += $valor;
            $proveedores[$providerKey]['empresas'][$empresaKey]['totales']['abono'] += $abono;
            $proveedores[$providerKey]['empresas'][$empresaKey]['totales']['saldo'] += $saldo;

            $proveedores[$providerKey]['empresas'][$empresaKey]['sucursales'][$sucursalKey]['totales']['valor'] += $valor;
            $proveedores[$providerKey]['empresas'][$empresaKey]['sucursales'][$sucursalKey]['totales']['abono'] += $abono;
            $proveedores[$providerKey]['empresas'][$empresaKey]['sucursales'][$sucursalKey]['totales']['saldo'] += $saldo;
        }

        foreach ($proveedores as &$proveedor) {
            foreach ($proveedor['empresas'] as &$empresa) {
                foreach ($empresa['sucursales'] as &$sucursal) {
                    $sucursal['facturas'] = collect($sucursal['facturas'])->values()->all();
                }
                unset($sucursal);

                $empresa['sucursales'] = array_values($empresa['sucursales']);
            }
            unset($empresa);

            $proveedor['empresas'] = array_values($proveedor['empresas']);
        }
        unset($proveedor);

        return array_values($proveedores);
    }

    private static function buildEmpresasParaReportes(array $proveedores): array
    {
        $empresas = [];

        foreach ($proveedores as $proveedor) {
            foreach ($proveedor['empresas'] ?? [] as $empresa) {
                $empresaKey = ($empresa['conexion_id'] ?? '') . '|' . ($empresa['empresa_codigo'] ?? '');

                if (! isset($empresas[$empresaKey])) {
                    $empresas[$empresaKey] = [
                        'conexion_nombre' => $empresa['conexion_nombre'] ?? '',
                        'empresa_codigo' => $empresa['empresa_codigo'] ?? '',
                        'empresa_nombre' => $empresa['empresa_nombre'] ?? ($empresa['empresa_codigo'] ?? ''),
                        'proveedores' => [],
                        'totales' => [
                            'valor' => 0,
                            'abono' => 0,
                            'saldo' => 0,
                        ],
                    ];
                }

                $empresaData = [
                    'nombre' => $proveedor['proveedor_nombre'] ?? $proveedor['proveedor_codigo'],
                    'ruc' => $proveedor['proveedor_ruc'] ?? '',
                    'descripcion' => $proveedor['descripcion'] ?? '',
                    'totales' => $empresa['totales'] ?? ['valor' => 0, 'abono' => 0, 'saldo' => 0],
                    'sucursales' => $empresa['sucursales'] ?? [],
                ];

                $empresas[$empresaKey]['proveedores'][] = $empresaData;
                $empresas[$empresaKey]['totales']['valor'] += (float) ($empresa['totales']['valor'] ?? 0);
                $empresas[$empresaKey]['totales']['abono'] += (float) ($empresa['totales']['abono'] ?? 0);
                $empresas[$empresaKey]['totales']['saldo'] += (float) ($empresa['totales']['saldo'] ?? 0);
            }
        }

        return array_values($empresas);
    }

    private static function buildResumenPorEmpresaDesdeFacturas(array $facturas): array
    {
        return collect($facturas)
            ->groupBy(fn(array $factura) => $factura['empresa_nombre'] ?? $factura['empresa_codigo'] ?? 'N/D')
            ->map(function ($grupo, $empresa) {
                $valor = 0;
                $abono = 0;
                $saldo = 0;

                foreach ($grupo as $factura) {
                    $valorFactura = (float) ($factura['total'] ?? $factura['monto'] ?? $factura['saldo'] ?? 0);
                    $abonoFactura = (float) ($factura['abono'] ?? 0);
                    $saldoFactura = (float) ($factura['saldo_pendiente'] ?? max(0, ($factura['saldo'] ?? $valorFactura) - $abonoFactura));

                    $valor += $valorFactura;
                    $abono += $abonoFactura;
                    $saldo += $saldoFactura;
                }

                return [
                    'empresa' => $empresa,
                    'valor' => $valor,
                    'abono' => $abono,
                    'saldo' => $saldo,
                ];
            })
            ->values()
            ->all();
    }

    private static function buildTotalesDesdeFacturas(array $facturas): array
    {
        $valor = 0;
        $abono = 0;
        $saldo = 0;

        foreach ($facturas as $factura) {
            $valorFactura = (float) ($factura['total'] ?? $factura['monto'] ?? $factura['saldo'] ?? 0);
            $abonoFactura = (float) ($factura['abono'] ?? 0);
            $saldoFactura = (float) ($factura['saldo_pendiente'] ?? max(0, ($factura['saldo'] ?? $valorFactura) - $abonoFactura));

            $valor += $valorFactura;
            $abono += $abonoFactura;
            $saldo += $saldoFactura;
        }

        return [
            'valor' => $valor,
            'abono' => $abono,
            'saldo' => $saldo,
        ];
    }

    private static function buildComprasReportRows(array $compras): array
    {
        return collect($compras)
            ->groupBy(fn(array $factura) => ($factura['conexion_id'] ?? '') . '|' . ($factura['empresa_codigo'] ?? ''))
            ->map(function ($grupo) {
                $first = $grupo->first();
                $rows = $grupo->map(function (array $factura) {
                    $valor = (float) ($factura['total'] ?? $factura['monto'] ?? $factura['saldo'] ?? 0);
                    $abono = (float) ($factura['abono'] ?? 0);
                    $saldo = (float) ($factura['saldo_pendiente'] ?? max(0, ($factura['saldo'] ?? $valor) - $abono));

                    return [
                        'descripcion' => $factura['descripcion'] ?? '',
                        'numero' => $factura['numero'] ?? '',
                        'valor' => $valor,
                        'abono' => $abono,
                        'saldo' => $saldo,
                    ];
                })->values();

                $totales = [
                    'valor' => $rows->sum('valor'),
                    'abono' => $rows->sum('abono'),
                    'saldo' => $rows->sum('saldo'),
                ];

                return [
                    'conexion_nombre' => $first['conexion_nombre'] ?? '',
                    'empresa_nombre' => $first['empresa_nombre'] ?? ($first['empresa_codigo'] ?? 'N/D'),
                    'compras' => $rows->all(),
                    'totales' => $totales,
                ];
            })
            ->values()
            ->all();
    }

    private static function buildProveedorKey(?string $codigo, ?string $ruc, ?string $nombre): string
    {
        $ruc = preg_replace('/\s+/', '', (string) $ruc);
        $ruc = preg_replace('/[^0-9A-Za-z]/', '', $ruc);

        if (! empty($ruc)) {
            return 'ruc:' . mb_strtolower($ruc);
        }

        $nombre = mb_strtolower(trim((string) $nombre));
        $nombre = preg_replace('/\s+/', ' ', $nombre);

        if ($nombre !== '') {
            return 'nom:' . md5($nombre);
        }

        return 'cod:' . mb_strtolower(trim((string) $codigo));
    }
}
