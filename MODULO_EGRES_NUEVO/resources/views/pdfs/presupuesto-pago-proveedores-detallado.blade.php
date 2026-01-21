<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Presupuesto de pago a proveedores - Detallado</title>
    <style>
        html, body,
        .page,
        div, span, p,
        table, thead, tbody, tfoot, tr, th, td,
        .header-date, .header-block,
        .company-name, .doc-line, .doc-number, .doc-type,
        .left-info,
        .signatures, .signatures-fixed,
        .sign-table, .sign-cell, .sign-label, .sign-role,
        .pdf-footer {
            font-family: Arial, Helvetica, sans-serif !important;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #1f2937;
        }

        h1 {
            font-size: 18px;
            margin-bottom: 4px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        h2 {
            font-size: 14px;
            margin: 0;
            text-align: center;
            font-weight: normal;
        }

        h3 {
            font-size: 12px;
            margin: 0;
            text-align: center;
            font-weight: normal;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th,
        td {
            padding: 6px;
            border: 1px solid #e5e7eb;
            text-align: left;
        }

        th {
            background: #f3f4f6;
            font-weight: 700;
            text-transform: uppercase;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .subtitle {
            margin-top: 2px;
            font-size: 12px;
            text-align: center;
            color: #4b5563;
        }

        .section-title {
            margin-top: 16px;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }

        .provider-box {
            margin-top: 12px;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f8fafc;
        }

        .provider-header {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 6px;
            font-weight: 700;
            color: #111827;
        }

        .provider-meta {
            font-weight: 500;
            color: #374151;
        }

        .summary {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            padding: 8px;
            margin-top: 14px;
            font-weight: 700;
            text-align: right;
        }

        .signatures-wrap {
            margin-top: 70px;
        }

        .signatures-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        .signatures-table td {
            border: none !important;
            padding: 0 18px;
            vertical-align: top;
            text-align: center;
        }

        .signature-line {
            border-top: 1px solid #111827;
            margin: 0 auto;
            width: 90%;
            height: 1px;
        }

        .signature-name {
            margin-top: 6px;
            font-size: 12px;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
        }

        .signature-role {
            margin-top: 2px;
            font-size: 11px;
            font-weight: 400;
            color: #374151;
            text-transform: uppercase;
        }

        .logo {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 90px;
            height: auto;
        }
    </style>
</head>

<body>
    <img src="{{ public_path('images/LOGOADMG.png') }}" alt="Logo ADMG" class="logo">

    <h1>GRUPO EMPRESARIAL ADMG</h1>
    <h2>REPORTE DETALLADO DE PRESUPUESTO DE PAGO A PROVEEDORES</h2>
    <h3>{{ $descripcionReporte }}</h3>
    <p class="subtitle">Elaborado por: {{ $usuario ?? 'N/D' }}</p>

    @forelse ($empresas as $empresa)
        <div class="section-title">{{ $empresa['conexion_nombre'] }} - {{ $empresa['empresa_nombre'] }}</div>

        @forelse ($empresa['proveedores'] as $proveedor)
            <div class="provider-box">
                <div class="provider-header">
                    <div>
                        {{ $proveedor['nombre'] ?? '' }}
                        @if (!empty($proveedor['ruc']))
                            <span class="provider-meta">· RUC: {{ $proveedor['ruc'] }}</span>
                        @endif
                    </div>
                    <div class="provider-meta">Área: {{ $proveedor['area'] ?? '' }}</div>
                </div>
                <div class="provider-meta">{{ $proveedor['descripcion'] ?? '' }}</div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 18%">Sucursal</th>
                            <th style="width: 18%">Factura</th>
                            <th style="width: 16%" class="text-center">Fecha emisión</th>
                            <th style="width: 16%" class="text-center">Fecha vencimiento</th>
                            <th style="width: 16%" class="text-right">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($proveedor['facturas'] ?? [] as $factura)
                            <tr>
                                <td>{{ $factura['sucursal'] ?? '' }}</td>
                                <td>{{ $factura['numero'] ?? '' }}</td>
                                <td class="text-center">{{ $factura['fecha_emision'] ?? '' }}</td>
                                <td class="text-center">{{ $factura['fecha_vencimiento'] ?? '' }}</td>
                                <td class="text-right">
                                    ${{ number_format((float) ($factura['saldo'] ?? 0), 2, '.', ',') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center">Sin facturas asignadas</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="summary">
                    Total proveedor: ${{ number_format((float) ($proveedor['subtotal'] ?? 0), 2, '.', ',') }}
                </div>
            </div>
        @empty
            <p>No hay proveedores disponibles.</p>
        @endforelse
    @empty
        <p>No hay registros disponibles.</p>
    @endforelse

    @if (!empty($resumenEmpresas))
        <div class="section-title">Resumen por empresa</div>
        <table>
            <thead>
                <tr>
                    <th>Empresa</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resumenEmpresas as $resumen)
                    <tr>
                        <td>{{ $resumen['empresa'] ?? '' }}</td>
                        <td class="text-right">${{ number_format((float) ($resumen['total'] ?? 0), 2, '.', ',') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="summary">
        Total general: ${{ number_format((float) ($total ?? 0), 2, '.', ',') }}
    </div>

    <div class="signatures-wrap">
        <table class="signatures-table">
            <tr>
                <td>
                    <div class="signature-line"></div>
                    <div class="signature-name">Solicitante</div>
                    <div class="signature-role">Responsable</div>
                </td>
                <td>
                    <div class="signature-line"></div>
                    <div class="signature-name">Finanzas</div>
                    <div class="signature-role">Revisión</div>
                </td>
                <td>
                    <div class="signature-line"></div>
                    <div class="signature-name">Gerencia</div>
                    <div class="signature-role">Aprobación</div>
                </td>
            </tr>
        </table>
    </div>
</body>

</html>
