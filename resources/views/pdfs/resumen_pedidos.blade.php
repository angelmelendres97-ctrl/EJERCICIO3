<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Resumen de Pedido</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 0;
            padding: 10px;
        }

        .page {
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
            padding: 0px;
        }

        .header-date {
            text-align: right;
            margin-bottom: 10px;
        }

        .header-date .title-sub {
            font-size: 13px;
            font-weight: 600;
        }

        .header-block {
            text-align: center;
            margin-bottom: 10px;
        }

        .header-block .title-main {
            font-size: 16px;
            font-weight: 700;
        }

        .header-block .title-sub {
            font-size: 14px;
            font-weight: 700;
        }

        .flex {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }

        .left-info {
            width: 100%;
            font-size: 11px;
        }

        .col-7 {
            width: 60%;
            float: left;
        }

        .col-5 {
            width: 40%;
            float: left;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 13px;
        }

        table th,
        table td {
            border: 1px solid #333;
            padding: 4px;
            font-size: 11px;
        }

        table th {
            background: #f2f2f2;
            font-weight: 700;
            font-size: 11px;
        }

        td.center {
            text-align: center;
        }

        td.right {
            text-align: right;
        }

        .clearfix {
            clear: both;
        }

        @page {
            size: A4 landscape;
            margin: 20px;
        }

        /* FOOTER (USANDO ELEMENTO FIJO + CONTADORES) */
        /* Este método funciona en dompdf, mpdf y wkhtmltopdf */
        .pdf-footer {
            position: fixed;
            bottom: 10px;       /* distancia desde la parte inferior de la página */
            right: 20px;        /* alineado a la derecha (cámbialo si quieres centrar) */
            font-size: 12px;
            font-family: Arial, Helvetica, sans-serif;
        }

        /* El contenido se genera con :before usando los contadores */
        .pdf-footer .pagenum:before {
            content: "Pág " counter(page) " / " counter(pages);
        }

        /* Si el motor no soporta counter(pages) mostrará solo el número de página */
        .pdf-footer .pagenum-alt:before {
            content: "Pág " counter(page);
        }
    </style>
</head>

<body>
    <div class="page">

        <div class="header-date">
            <div class="title-sub">Machala,
                {{ mb_convert_case((new IntlDateFormatter('es_ES', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'America/Guayaquil', IntlDateFormatter::GREGORIAN, "d 'de' MMMM 'del' yyyy"))->format(new DateTime(date('Y-m-d'))), MB_CASE_TITLE, "UTF-8") }}
            </div>
        </div>

        <div class="header-block">
            <div class="title-main">{{ $resumen->empresa->nombre_empresa ?? 'Nombre de Empresa no disponible' }}</div>
            <div class="title-main">{{ str_pad($resumen->id, 8, '0', STR_PAD_LEFT) }}<label
                    style="font-size: 22px !important"> {{ $resumen->tipo }}</label></div>

        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:2px">Items</th>
                    <th style="width:8px">Fecha</th>
                    <th style="width:35px">Proveedor</th>
                    <th style="width:20px">Detalle</th>
                    <th style="width:15px">Pedido</th>
                    <th style="width:15px">Orden Compra</th>
                    <th style="width:5px">Total</th>
                </tr>
            </thead>
            <tbody>

                @php
                    $total_oc = 0;
                @endphp

                @foreach($resumen->detalles as $key => $detalle)
                    <tr>
                        <td class="center">{{ $key + 1 }}</td>

                        @php
                            $data_orden_compra = \App\Models\OrdenCompra::find($detalle->id_orden_compra);
                            $total_oc += $data_orden_compra->total;
                        @endphp

                        <td>{{ date_format(date_create($data_orden_compra->fecha_pedido), 'Y-m-d') }}</td>
                        <td class="left">{{ $data_orden_compra->proveedor }}</td>
                        <td class="left">{{ strtoupper($data_orden_compra->observaciones) }}</td>
                        <td class="left">{{ $data_orden_compra->pedidos_importados }}</td>
                        <td class="left">{{ str_pad($data_orden_compra->id, 8, '0', STR_PAD_LEFT) }}</td>
                        <td class="right">$ {{ number_format($data_orden_compra->total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="row-flex" style="">
            <div class="col-8">
                <div class="flex" style="margin-top: 5px; text-align: right !important;">
                    <div class="left-info">
                        <b>TOTAL: $ {{ number_format($total_oc, 2) }} </b>
                        <br>
                    </div>
                </div>
            </div>

            <div class="col-4">
                <table>
                    <thead>
                        <tr>
                            <th style="width:80%; border: none; border-collapse: collapse;" class="left"></th>
                            <td style="width:10%" class="right"><b>TOTAL $</b></td>
                            <td style="width:10%" class="right"><b>$ {{ number_format($total_oc, 2) }}</b></td>
                        </tr>
                    </thead>
                </table>
            </div>

            <div class="clearfix"></div>
        </div>

    </div>

    <!-- FOOTER FIJO que se repetirá en cada página -->
    <div class="pdf-footer" aria-hidden="true">
        <!-- Si su generador soporta counter(pages) se mostrará "Pág X / Y" -->
        <span class="pagenum"></span>
        <!-- Si no, puede usar la alternativa (solo número de página) -->
        <span style="display:none" class="pagenum-alt"></span>
    </div>

</body>

</html>
