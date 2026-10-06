<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>{{ $reporte->titulo }}</title>
<style>
    @page { margin: 26mm 14mm 20mm 14mm; }
    * { box-sizing: border-box; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #1F2A37; line-height: 1.35; }

    /* Encabezado y pie fijos en todas las páginas */
    header { position: fixed; top: -20mm; left: 0; right: 0; height: 16mm; border-bottom: 1.5pt solid #0E5A73; }
    header .marca { font-size: 13pt; font-weight: bold; color: #0E5A73; letter-spacing: .5pt; }
    header .franja { display: inline-block; width: 3pt; height: 11pt; margin-right: 1pt; vertical-align: -1pt; }
    header .empresa { font-size: 8pt; color: #5B6B7C; }
    header .derecha { position: absolute; right: 0; top: 0; text-align: right; font-size: 7.5pt; color: #5B6B7C; }
    footer { position: fixed; bottom: -14mm; left: 0; right: 0; height: 10mm; border-top: .5pt solid #CBD3DC; padding-top: 2mm; font-size: 7.5pt; color: #5B6B7C; }

    h1 { font-size: 15pt; margin: 0 0 1mm; }
    .subtitulo { font-size: 10pt; color: #5B6B7C; margin: 0 0 4mm; }
    h2 { font-size: 10.5pt; margin: 6mm 0 1.5mm; padding-bottom: 1mm; border-bottom: .75pt solid #CBD3DC; page-break-after: avoid; }
    .descripcion { color: #5B6B7C; margin: 0 0 2mm; }

    table { width: 100%; border-collapse: collapse; }
    .resumen td { padding: 1.2mm 2mm; border: .5pt solid #CBD3DC; vertical-align: top; }
    .resumen td.etiqueta { width: 22%; background: #EEF1F4; font-weight: bold; }

    .datos thead { display: table-header-group; } /* repite el encabezado en cada página */
    .datos th { background: #0E5A73; color: #fff; font-weight: bold; text-align: left; padding: 1.4mm 1.5mm; font-size: 7.5pt; }
    .datos td { padding: 1.2mm 1.5mm; border-bottom: .5pt solid #E1E6EB; vertical-align: top; }
    .datos tr:nth-child(even) td { background: #F6F8FA; }
    .datos .num { text-align: right; white-space: nowrap; }
    .datos tr.total td { font-weight: bold; border-top: 1pt solid #1F2A37; background: #fff; }
    tr { page-break-inside: avoid; }

    .vacio { padding: 3mm; color: #5B6B7C; font-style: italic; border: .5pt dashed #CBD3DC; }
    .notas { margin-top: 6mm; padding: 3mm 4mm; background: #FDF1D6; border-left: 2.5pt solid #A86F00; }
    .notas p { margin: 0 0 1mm; font-weight: bold; }
    .notas ul { margin: 0; padding-left: 4mm; }

    .firmas { margin-top: 18mm; page-break-inside: avoid; }
    .firmas td { width: 50%; padding: 0 8mm; text-align: center; vertical-align: top; }
    .firmas .linea { border-top: .75pt solid #1F2A37; padding-top: 1.5mm; }
    .verificacion { margin-top: 8mm; font-size: 7pt; color: #5B6B7C; }
</style>
</head>
<body>
<header>
    <div>
        <span class="franja" style="background:#1E8E4E"></span><span class="franja" style="background:#A86F00"></span><span class="franja" style="background:#C2362F"></span>
        <span class="marca">SI-GESTA</span>
    </div>
    <div class="empresa">
        {{ $emision->empresa['nombre'] }}
        @if (!empty($emision->empresa['nit'])) · NIT {{ $emision->empresa['nit'] }} @endif
        @if (!empty($emision->empresa['registro_sanitario'])) · Reg. sanitario {{ $emision->empresa['registro_sanitario'] }} @endif
    </div>
    <div class="derecha">
        Emitido el {{ $emision->fecha->format('d/m/Y H:i') }}<br>
        por {{ $emision->usuario }}<br>
        Código <strong>{{ $emision->codigo }}</strong>
    </div>
</header>

<footer>
    SI-GESTA · {{ $reporte->titulo }} · Código de verificación {{ $emision->codigo }}
</footer>

<main>
    <h1>{{ $reporte->titulo }}</h1>
    @if ($reporte->subtitulo)
        <p class="subtitulo">{{ $reporte->subtitulo }}</p>
    @endif

    @if ($reporte->resumen)
        <table class="resumen">
            @foreach (array_chunk($reporte->resumen, 2) as $par)
                <tr>
                    @foreach ($par as [$etiqueta, $valor])
                        <td class="etiqueta">{{ $etiqueta }}</td>
                        <td>{{ $valor }}</td>
                    @endforeach
                    @if (count($par) === 1)
                        <td class="etiqueta"></td><td></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif

    @foreach ($reporte->secciones as $seccion)
        <h2>{{ $seccion->titulo }}</h2>
        @if ($seccion->descripcion)
            <p class="descripcion">{{ $seccion->descripcion }}</p>
        @endif

        @if (count($seccion->filas) === 0)
            <div class="vacio">{{ $seccion->vacio }}</div>
        @else
            <table class="datos">
                <thead>
                    <tr>
                        @foreach ($seccion->columnas as $columna)
                            <th class="{{ \App\Reportes\Formato::esNumerico($columna['tipo'] ?? null) ? 'num' : '' }}">{{ $columna['titulo'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($seccion->filas as $fila)
                        <tr>
                            @foreach ($seccion->columnas as $columna)
                                <td class="{{ \App\Reportes\Formato::esNumerico($columna['tipo'] ?? null) ? 'num' : '' }}">
                                    {{ \App\Reportes\Formato::celda($columna['tipo'] ?? null, $fila[$columna['clave']] ?? null) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    @if ($seccion->totales)
                        <tr class="total">
                            @foreach ($seccion->columnas as $columna)
                                @php $valor = $seccion->totales[$columna['clave']] ?? null; @endphp
                                <td class="{{ \App\Reportes\Formato::esNumerico($columna['tipo'] ?? null) ? 'num' : '' }}">
                                    {{ $valor === null ? '' : (is_string($valor) && !is_numeric($valor) ? $valor : \App\Reportes\Formato::celda($columna['tipo'] ?? null, $valor)) }}
                                </td>
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
        @endif
    @endforeach

    @if ($reporte->notas)
        <div class="notas">
            <p>Observaciones</p>
            <ul>
                @foreach ($reporte->notas as $nota)
                    <li>{{ $nota }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($reporte->conFirmas)
        <table class="firmas">
            <tr>
                <td><div class="linea">Responsable de calidad e inocuidad<br>Nombre, firma y fecha</div></td>
                <td><div class="linea">Responsable de producción / almacén<br>Nombre, firma y fecha</div></td>
            </tr>
        </table>
    @endif

    <p class="verificacion">
        Documento generado por SI-GESTA a partir del registro inmutable de movimientos de inventario.
        Para confirmar su autenticidad, consulte el código {{ $emision->codigo }} en el módulo de reportes del sistema.
    </p>
</main>
</body>
</html>
