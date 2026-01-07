<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Orden de Compra - Formato</title>

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
            /* Deja espacio para el footer fijo (políticas + firmas) */
            padding: 0px 0px 260px;
            position: relative;
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

        .header-block .title-insub {
            font-size: 12px;
            font-weight: 700;
        }

        .stamp {
            position: absolute;
            top: 10px;
            right: 10px;
            border: 2px solid #000;
            padding: 6px 10px;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        /* Cabecera info (2 columnas) */
        .row {
            width: 100%;
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

        .clearfix {
            clear: both;
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

        th.left {
            text-align: left;
        }

        td.center {
            text-align: center;
        }

        td.right {
            text-align: right;
        }

        /* ======= BLOQUE RESUMEN (Observaciones + mini-info + totales) ======= */
        .resume-wrap {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .resume-wrap td {
            border: none;
            padding: 0;
            vertical-align: top;
        }

        .resume-left {
            width: 66%;
            padding-right: 8px;
        }

        .resume-right {
            width: 34%;
        }

        .obs-box {
            border: 1px solid #000;
            padding: 6px 8px;
            font-size: 11px;
            min-height: 35px;
        }

        .obs-text {
            margin-top: 4px;
            white-space: pre-line;
        }

        .mini-info {
            width: 65%;
            border-collapse: collapse;
            margin-top: 6px;
            font-size: 11px;
        }

        .mini-info th,
        .mini-info td {
            border: 1px solid #333;
            padding: 4px;
        }

        .mini-info th {
            background: #f2f2f2;
            text-align: left;
            width: 50%;
        }

        .totales {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 0;
        }

        .totales th,
        .totales td {
            border: 1px solid #333;
            padding: 4px;
        }

        .totales th {
            background: #f2f2f2;
            text-align: left;
        }

        /* ======= FOOTER FIJO ======= */
        .footer {
            position: fixed;
            bottom: 20px;
            left: 20px;
            right: 20px;
        }

        .policies {
            margin-top: 0;
            font-size: 11px;
        }

        /* Firmas: SIN “marco contenedor” */
        .signatures {
            margin-top: 40px;
        }

        .sign-table {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }

        .sign-table td {
            border: none;
            padding: 0;
        }

        .sign-cell {
            width: 50%;
            text-align: center;
            padding-top: 45px;
            /* ← MÁS ESPACIO PARA FIRMAR */
        }

        .sign-line {
            border-top: 1px solid #000;
            width: 80%;
            margin: 0 auto 6px auto;
            height: 1px;
        }

        .sign-label {
            font-size: 11px;
            margin-bottom: 3px;
        }

        .sign-role {
            font-size: 11px;
        }

        @page {
            size: A4 portrait;
            margin: 20px;
        }
    </style>
</head>

<body>
    <div class="page">

        <div class="stamp">{{ $ordenCompra->presupuesto }}</div>

        <div class="header-block">
            <div class="title-main">INMOBILIARIA BUENA RENTA SA</div>
            <div class="title-sub">ORDEN DE COMPRA N.- {{ $ordenCompra->numero_oc ?? '0000000' }}</div>

            @php
                $nombre_formato_oc = $ordenCompra->formato == 'P' ? 'Proforma' : 'Factura';
                $numero_formato_oc = $ordenCompra->numero_factura_proforma ?? '';
            @endphp

            <div class="title-insub">{{ $nombre_formato_oc }} N.° {{ $numero_formato_oc }}</div>
        </div>

        <!-- CABECERA INFO -->
        <div class="row">
            <div class="col-7">
                <div class="flex">
                    <div class="left-info">
                        <b>Ciudad y Fecha: </b> MACHALA {{ $ordenCompra->fecha_pedido->format('d/m/Y') }}<br>
                        <b>Proveedor: </b> {{ $ordenCompra->proveedor }}<br>
                        <b>Para Uso De: </b> {{ $ordenCompra->uso_compra }}<br>
                        <b>Solicitado Por: </b> {{ $ordenCompra->solicitado_por }}<br>
                        <b>Lugar de Entrega: </b> IMBUESA<br>
                    </div>
                </div>
            </div>

            <div class="col-5">
                <div class="flex">
                    <div class="left-info">
                        <b>Plazo de Entrega: </b> {{ $ordenCompra->fecha_entrega->format('d/m/Y') }}<br>
                        <b>Direccion: </b> {{ $ordenCompra->direccion ?? '' }}<br>
                        <b>Telefono: </b> {{ $ordenCompra->telefono ?? '0998612034' }}<br>
                        <b>Forma de Pago: </b> {{ $ordenCompra->forma_pago ?? '' }}<br>
                    </div>
                </div>
            </div>

            <div class="clearfix"></div>
        </div>

        <!-- DETALLE -->
        <table>
            <thead>
                <tr>
                    <th style="width:20px">#</th>
                    <th style="width:100px">Código</th>
                    <th>Descripción</th>
                    <th style="width:40px">Unid.</th>
                    <th style="width:60px">Cant.</th>
                    <th style="width:60px">Precio U.</th>
                    <th style="width:60px">Total</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($ordenCompra->detalles as $key => $detalle)
                    <tr>
                        <td class="center">{{ $key + 1 }}</td>
                        <td>{{ $detalle->codigo_producto }}</td>
                        <td>{{ $detalle->producto }}</td>
                        <td class="center">{{ $detalle->unidad ?? 'UN' }}</td>
                        <td class="center">{{ $detalle->cantidad }}</td>
                        <td class="right">$ {{ number_format($detalle->costo, 2) }}</td>
                        <td class="right">$ {{ number_format($detalle->total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @php
            $nombre_tipo_oc = '';
            if ($ordenCompra->tipo_oc == 'REEMB') {
                $nombre_tipo_oc = 'REEMBOLSO';
            } elseif ($ordenCompra->tipo_oc == 'COMPRA') {
                $nombre_tipo_oc = 'COMPRA';
            } elseif ($ordenCompra->tipo_oc == 'PAGO') {
                $nombre_tipo_oc = 'PAGO';
            } elseif ($ordenCompra->tipo_oc == 'REGUL') {
                $nombre_tipo_oc = 'REGULARIZACION';
            } elseif ($ordenCompra->tipo_oc == 'CAJAC') {
                $nombre_tipo_oc = 'CAJA CHICA';
            }

            $numero_pedidos = $ordenCompra->pedidos_importados ?? '';
            $txt_pedidos = '';

            if (!empty($numero_pedidos)) {
                if (str_contains($numero_pedidos, ',')) {
                    $array_pedidos = explode(',', $numero_pedidos);
                    foreach ($array_pedidos as $numero_pedi) {
                        $txt_pedidos .= trim($numero_pedi) . ' - ';
                    }
                    $txt_pedidos = rtrim($txt_pedidos, ' - ');
                } else {
                    $txt_pedidos = trim($numero_pedidos);
                }
            }
        @endphp

        <!-- RESUMEN (OBS + MINI INFO + TOTALES) -->
        <table class="resume-wrap">
            <tr>
                <!-- IZQUIERDA -->
                <td class="resume-left">
                    <div class="obs-box">
                        <b>Observaciones:</b>
                        <div class="obs-text">{{ $ordenCompra->observaciones }}</div>
                    </div>

                    <table class="mini-info">
                        <tr>
                            <th>Tipo Orden Compra</th>
                            <td>{{ $nombre_tipo_oc }}</td>
                        </tr>

                        @if ($ordenCompra->tipo_oc == 'REEMB')
                            <tr>
                                <th>Nombre reembolso</th>
                                <td>{{ $ordenCompra->nombre_reembolso ?? '' }}</td>
                            </tr>
                        @endif

                        <tr>
                            <th>Presupuesto</th>
                            <td>{{ $ordenCompra->presupuesto }}</td>
                        </tr>

                        <tr>
                            <th>Pedidos Compra Afectados</th>
                            <td>{{ $txt_pedidos }}</td>
                        </tr>
                    </table>
                </td>

                <!-- DERECHA -->
                <td class="resume-right">
                    <table class="totales">
                        <tr>
                            <th style="width:60%" class="left">Subtotal</th>
                            <td style="width:40%" class="right">$ {{ number_format($ordenCompra->subtotal, 2) }}</td>
                        </tr>
                        <tr>
                            <th class="left">Descuento</th>
                            <td class="right">$ {{ number_format($ordenCompra->total_descuento, 2) }}</td>
                        </tr>
                        <tr>
                            <th class="left">Iva</th>
                            <td class="right">$ {{ number_format($ordenCompra->total_impuesto, 2) }}</td>
                        </tr>
                        <tr>
                            <th class="left">Total</th>
                            <td class="right">$ {{ number_format($ordenCompra->total, 2) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- FOOTER FIJO -->
        <div class="footer">
            <div class="policies">
                <b>POLÍTICAS PARA LA ORDEN DE COMPRA:</b><br>
                A) Este documento es válido solamente si está firmado por la persona autorizada para aprobar
                compras.<br>
                B) El proveedor sera responsable de revisar los precios establecidos en la presente orden de compra,
                esten acorde a los cotizados. Y no podran variar segun el tiempo de vigencia establecido en la
                cotizacion<br>
                C) El Proveedor sera responsable de cumplir con las especificaciones y el tiempo ofrecido y acordado con
                INMOBILIARIA BUENA RENTA SA. En caso de modificar las especificaciones deberar informar a INMOBILIARIA
                BUENA RENTA SA para que esta resuelva si aprueba o no tal modificacion<br>
                D) El caso de inclumpliento de tiempos de entrega, INMOBILIARIA BUENA RENTA SA decidira si aceptar o no
                el pedido, y en caso de recibirlo podra multar al proveedor, escontando costos de afectacion por la no
                recepcion de la mercaderia en la fecha acordada<br>
            </div>

            <div class="signatures">
                <table class="sign-table">
                    <tr>
                        <td class="sign-cell">
                            <div class="sign-line"></div>
                            <div class="sign-label"><b>Elaborado por</b></div>
                            <div class="sign-role"><b>COMPRAS</b></div>
                        </td>

                        <td class="sign-cell">
                            <div class="sign-line"></div>
                            <div class="sign-label"><b>Aprobado por</b></div>
                            <div class="sign-role"><b>GERENCIA</b></div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

    </div>
</body>

</html>
