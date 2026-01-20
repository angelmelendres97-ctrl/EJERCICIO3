<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Egreso - Solicitud de Pago</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #1f2937;
        }
        h1, h2, h3 {
            margin: 0 0 8px 0;
        }
        .section {
            margin-bottom: 24px;
        }
        .meta {
            margin-bottom: 16px;
        }
        .meta div {
            margin-bottom: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 6px;
            text-align: left;
        }
        th {
            background-color: #f3f4f6;
            font-weight: 700;
        }
        .text-right {
            text-align: right;
        }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            background-color: #ecfeff;
            color: #0e7490;
            font-size: 11px;
            font-weight: 700;
        }
    </style>
</head>
<body>
    @php
        $money = fn($v) => '$' . number_format((float) ($v ?? 0), 2, '.', ',');
    @endphp

    <div class="section">
        <h1>Reporte de Egreso</h1>
        <div class="meta">
            <div><strong>Solicitud:</strong> #{{ $solicitud->id }}</div>
            <div><strong>Fecha:</strong> {{ optional($solicitud->fecha)->format('Y-m-d') }}</div>
            <div><strong>Motivo:</strong> {{ $solicitud->motivo ?? 'N/D' }}</div>
            <div><strong>Empresa:</strong> {{ $solicitud->empresa?->nombre_empresa ?? 'N/D' }}</div>
            <div><strong>Creado por:</strong> {{ $solicitud->creadoPor?->name ?? 'N/D' }}</div>
            <div><strong>Estado:</strong> <span class="badge">Generado Egreso</span></div>
        </div>
    </div>

    @foreach ($registros as $registro)
        <div class="section">
            <h2>Asiento #{{ $registro['asiento'] ?? 'N/D' }}</h2>
            <div class="meta">
                <div><strong>Empresa:</strong> {{ $registro['empresa'] ?? 'N/D' }}</div>
                <div><strong>Sucursal:</strong> {{ $registro['sucursal'] ?? 'N/D' }}</div>
                <div><strong>Ejercicio:</strong> {{ $registro['ejercicio'] ?? 'N/D' }}</div>
                <div><strong>Periodo:</strong> {{ $registro['periodo'] ?? 'N/D' }}</div>
                <div><strong>Fecha:</strong> {{ $registro['fecha'] ?? 'N/D' }}</div>
                <div><strong>Beneficiario:</strong> {{ $registro['beneficiario'] ?? 'N/D' }}</div>
                <div><strong>Detalle:</strong> {{ $registro['detalle'] ?? 'N/D' }}</div>
            </div>

            <h3>Directorio</h3>
            <table>
                <thead>
                    <tr>
                        <th>Proveedor</th>
                        <th>Factura</th>
                        <th>Fecha vencimiento</th>
                        <th>Detalle</th>
                        <th class="text-right">Débito ML</th>
                        <th class="text-right">Crédito ML</th>
                        <th class="text-right">Débito ME</th>
                        <th class="text-right">Crédito ME</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($registro['directorio'] ?? [] as $entry)
                        <tr>
                            <td>{{ $entry['proveedor'] ?? 'N/D' }}</td>
                            <td>{{ $entry['factura'] ?? 'N/D' }}</td>
                            <td>{{ $entry['fecha_vencimiento'] ?? 'N/D' }}</td>
                            <td>{{ $entry['detalle'] ?? 'N/D' }}</td>
                            <td class="text-right">{{ $money($entry['debito_local'] ?? 0) }}</td>
                            <td class="text-right">{{ $money($entry['credito_local'] ?? 0) }}</td>
                            <td class="text-right">{{ $money($entry['debito_extranjera'] ?? 0) }}</td>
                            <td class="text-right">{{ $money($entry['credito_extranjera'] ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">Sin registros de directorio.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <h3>Diario</h3>
            <table>
                <thead>
                    <tr>
                        <th>Cuenta</th>
                        <th>Documento</th>
                        <th>Detalle</th>
                        <th>Centro costo</th>
                        <th>Centro actividad</th>
                        <th class="text-right">Débito</th>
                        <th class="text-right">Crédito</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($registro['diario'] ?? [] as $entry)
                        <tr>
                            <td>{{ $entry['cuenta'] ?? 'N/D' }}</td>
                            <td>{{ $entry['documento'] ?? 'N/D' }}</td>
                            <td>{{ $entry['detalle'] ?? 'N/D' }}</td>
                            <td>{{ $entry['centro_costo'] ?? 'N/D' }}</td>
                            <td>{{ $entry['centro_actividad'] ?? 'N/D' }}</td>
                            <td class="text-right">{{ $money($entry['debito'] ?? 0) }}</td>
                            <td class="text-right">{{ $money($entry['credito'] ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">Sin registros de diario.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</body>
</html>
