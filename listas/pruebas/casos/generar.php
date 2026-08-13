<?php
/* Genera los CSV de prueba con las mismas características que los del SAT:
   windows-1252, CRLF, preámbulo antes del encabezado. Se corre una vez; los
   archivos resultantes son el artefacto y van al repositorio. */

$destino = $argv[1] ?? '.';

function escribir(string $ruta, array $lineas): void
{
    $txt = implode("\r\n", $lineas) . "\r\n";
    // Los archivos del SAT son windows-1252, no UTF-8. Que estos también lo
    // sean es parte de lo que se prueba: el lector tiene que convertirlos.
    file_put_contents($ruta, mb_convert_encoding($txt, 'Windows-1252', 'UTF-8'));
    echo basename($ruta), '  ', filesize($ruta), " bytes\n";
}

/* ---- 69-B: 20 columnas, 2 líneas de preámbulo ------------------------- */
$preambulo69b = function (string $fecha, string $titulo) {
    return [
        '"Información actualizada al ' . $fecha . '; los listados a que se hace mención, son de '
        . 'carácter público, y pueden ser consultados en el Portal del SAT.",,,,,,,,,,,,,,,,,,,',
        $titulo . ',,,,,,,,,,,,,,,,,,,',
        'No,RFC,Nombre del Contribuyente,Situación del contribuyente,'
        . 'Número y fecha de oficio global de presunción SAT,Publicación página SAT presuntos,'
        . 'Número y fecha de oficio global de presunción DOF,Publicación DOF presuntos,'
        . 'Número y fecha de oficio global de contribuyentes que desvirtuaron SAT,Publicación página SAT desvirtuados,'
        . 'Número y fecha de oficio global de contribuyentes que desvirtuaron DOF,Publicación DOF desvirtuados,'
        . 'Número y fecha de oficio global de definitivos SAT,Publicación página SAT definitivos,'
        . 'Número y fecha de oficio global de definitivos DOF,Publicación DOF definitivos,'
        . 'Número y fecha de oficio global de sentencia favorable SAT,Publicación página SAT sentencia favorable,'
        . 'Número y fecha de oficio global de sentencia favorable DOF,Publicación DOF sentencia favorable',
    ];
};

/* fila de 20 columnas con lo mínimo relleno */
$fila69b = function (int $n, string $rfc, string $nombre, string $situacion, string $oficio) {
    $c = array_fill(0, 20, '');
    $c[0] = (string)$n; $c[1] = $rfc; $c[2] = '"' . $nombre . '"';
    $c[3] = $situacion; $c[4] = '"' . $oficio . '"'; $c[5] = '01/03/2020';
    return implode(',', $c);
};

/* ---- delta: mayo -> junio -------------------------------------------- */
escribir("$destino/delta_mayo.csv", array_merge(
    $preambulo69b('31 de mayo de 2026', 'Listado completo de contribuyentes (Artículo 69-B del CFF)'),
    [
        $fila69b(1, 'AAA010101AAA', 'PRIMERA EMPRESA, S.A. DE C.V.',   'Definitivo',  '500-05-2020-1111 de fecha 1 de marzo de 2020'),
        $fila69b(2, 'BBB020202BBB', 'SEGUNDA EMPRESA, S.A. DE C.V.',   'Presunto',    '500-05-2026-2222 de fecha 2 de abril de 2026'),
        $fila69b(3, 'ÑAÑ030303CCC', 'TERCERA ÑOÑA, S.A. DE C.V.',      'Presunto',    '500-05-2026-3333 de fecha 3 de abril de 2026'),
        $fila69b(4, 'DDD040404DDD', 'CUARTA EMPRESA, S.A. DE C.V.',    'Desvirtuado', '500-05-2021-4444 de fecha 4 de mayo de 2021'),
        $fila69b(5, 'XXXXXXXXXXXX', 'Información suprimida en cumplimiento de la Ley Federal de Protección de Datos Personales', 'Definitivo', '500-05-2019-5555 de fecha 5 de junio de 2019'),
        ',,,,,,,,,,,,,,,,,,,',   // fila de relleno: los archivos reales las traen al final
    ]));

escribir("$destino/delta_junio.csv", array_merge(
    $preambulo69b('30 de junio de 2026', 'Listado completo de contribuyentes (Artículo 69-B del CFF)'),
    [
        // AAA sigue igual · BBB y ÑAÑ pasan a definitivo · DDD desaparece · EEE es nuevo
        $fila69b(1, 'AAA010101AAA', 'PRIMERA EMPRESA, S.A. DE C.V.',   'Definitivo', '500-05-2020-1111 de fecha 1 de marzo de 2020'),
        $fila69b(2, 'BBB020202BBB', 'SEGUNDA EMPRESA, S.A. DE C.V.',   'Definitivo', '500-05-2026-2222 de fecha 2 de abril de 2026'),
        $fila69b(3, 'ÑAÑ030303CCC', 'TERCERA ÑOÑA, S.A. DE C.V.',      'Definitivo', '500-05-2026-3333 de fecha 3 de abril de 2026'),
        $fila69b(4, 'XXXXXXXXXXXX', 'Información suprimida en cumplimiento de la Ley Federal de Protección de Datos Personales', 'Definitivo', '500-05-2019-5555 de fecha 5 de junio de 2019'),
        $fila69b(5, 'EEE050505EEE', 'QUINTA EMPRESA, S.A. DE C.V.',    'Presunto',   '500-05-2026-5555 de fecha 30 de junio de 2026'),
        ',,,,,,,,,,,,,,,,,,,',
    ]));

/* ---- Ñ, suprimidos y RFC rotos --------------------------------------- */
escribir("$destino/enes_y_suprimidos.csv", array_merge(
    $preambulo69b('31 de mayo de 2026', 'Listado completo de contribuyentes (Artículo 69-B del CFF)'),
    [
        $fila69b(1, 'ÑAÑ140114GY4',  'ÑEHAHA & ÑEAHAHA, S.A. DE C.V.', 'Definitivo', '500-05-2015-34025 de fecha 30 de octubre de 2015'),
        $fila69b(2, 'ÑEX121116KM4',  'ÑOVEÑA EXPORTACION',             'Presunto',   '500-05-2026-1000 de fecha 1 de enero de 2026'),
        $fila69b(3, 'XXXXXXXXXXXX',  'Información suprimida en cumplimiento de la Ley Federal de Protección de Datos Personales', 'Definitivo', '500-05-2019-2000 de fecha 2 de febrero de 2019'),
        $fila69b(4, 'ABC',           'RFC DEMASIADO CORTO',            'Presunto',   '500-05-2026-3000 de fecha 3 de marzo de 2026'),
        $fila69b(5, '',              'SIN RFC',                        'Presunto',   '500-05-2026-4000 de fecha 4 de abril de 2026'),
        $fila69b(6, 'AAAA010101AAA', 'PERSONA FISICA DE PRUEBA',       'Presunto',   '500-05-2026-5000 de fecha 5 de mayo de 2026'),
        $fila69b(7, 'AAA-08 08 08.HL8', 'CON GUIONES Y ESPACIOS',      'Definitivo', '500-05-2018-6000 de fecha 6 de junio de 2018'),
    ]));

/* ---- 69-B Bis: registro partido en dos líneas ------------------------- */
/* El salto de línea va DENTRO del campo entrecomillado. Es el caso real de la
   fila 2 de Listado_69_B_Bis_Completo.csv documentado en ESQUEMAS.md §6. */
escribir("$destino/bis_fila_partida.csv", [
    '"Información actualizada al 12 de marzo de 2026; los listados son de carácter público.",,,,,,,,,,,',
    'Listado completo 69-B Bis,,,,,,,,,,,',
    'No.,RFC,Nombre del Contribuyente,Situación del contribuyente,'
    . 'Número y fecha de oficio global definitivo SAT,Publicación página SAT definitivo,'
    . 'Número y fecha de oficio global definitivo DOF,Publicación DOF definitivo,'
    . 'Número y fecha de oficio global de sentencia favorable SAT,Publicación página SAT sentencia favorable,'
    . 'Número y fecha de oficio global de sentencia favorable DOF,Publicación DOF sentencia favorable',
    '1,AAA010101AAA,PRIMERA EMPRESA,Definitivo,900-04-2024-79 de 24 de enero de 2024,24/01/2024,,,,,,',
    '2,CPH061010RB7,"CONSTRUCTORA DE PROYECTOS HIDROELECTRICOS, ',
    'S.A. DE C.V.",Definitivo,900-04-00-00-00-2024-80 de 25 de enero de 2024,25/01/2024,,,,,,',
    '3,BBB020202BBB,TERCERA EMPRESA,Sentencia Favorable,,,,,900-04-2024-81 de 26 de enero de 2024,26/01/2024,,',
]);

/* ---- Artículo 69: 6 columnas, sin preámbulo --------------------------- */
escribir("$destino/art69_muestra.csv", [
    'RFC,RAZON SOCIAL,TIPO PERSONA,SUPUESTO,FECHA DE PRIMERA PUBLICACION,ENTIDAD FEDERATIVA',
    'AAG090703QT6,APLICA AGUASCALIENTES SA DE CV,M,FIRMES,01/01/2014,CIUDAD DE MEXICO',
    'AAAA730727JE3,ALTAMIRANO ABENDAÑO ALEJANDRO,F,NO LOCALIZADOS,15/03/2019,JALISCO',
    // Fila sin SUPUESTO y sin TIPO PERSONA: está así en la muestra de Firmes.csv
    'BBB020202BBB,EMPRESA SIN SUPUESTO SA DE CV,,,10/10/2020,NUEVO LEON',
]);
