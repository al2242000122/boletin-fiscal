<?php
/* ============================================================================
   probar_parser.php — comprueba el parser contra los archivos reales.

   Uso:  php cron/probar_parser.php <carpeta-con-los-csv>

   No inventa fixtures: corre sobre lo que publica el SAT y contrasta los
   resultados con lo que quedó medido en docs/ESQUEMAS.md. Si el SAT cambia
   algo, esto lo delata en vez de que se descubra en producción.
   ============================================================================ */

require __DIR__ . '/lib/csv_sat.php';

$dir = $argv[1] ?? __DIR__ . '/../privado/listas/raw';
if (!is_dir($dir)) { fwrite(STDERR, "No existe la carpeta: $dir\n"); exit(2); }

/* archivo => [familia, lo que esperamos según ESQUEMAS.md] */
$casos = [
    'l69b.csv'        => ['art69b',     ['columnas' => 20, 'preambulo' => 2, 'fecha' => '2026-05-31', 'filas' => 14523]],
    'presuntos.csv'   => ['art69b',     ['columnas' => 23, 'preambulo' => 2, 'fecha' => '2026-05-31', 'filas' => 754]],
    'bis.csv'         => ['art69b_bis', ['columnas' => 12, 'preambulo' => 2, 'fecha' => '2026-03-12', 'filas' => null]],
    'firmes_muestra.csv'  => ['art69',  ['columnas' => 6,  'preambulo' => 0, 'fecha' => null, 'filas' => null]],
    'nolocal_muestra.csv' => ['art69',  ['columnas' => 6,  'preambulo' => 0, 'fecha' => null, 'filas' => null]],
];

$fallos = 0;
$pruebas = 0;
function comprueba(string $q, $esperado, $obtenido): void
{
    global $fallos, $pruebas;
    if ($esperado === null) { printf("      %-26s %s\n", $q, is_scalar($obtenido) ? $obtenido : '—'); return; }
    $pruebas++;
    $ok = $esperado == $obtenido;
    if (!$ok) $fallos++;
    printf("      %-26s %-12s %s\n", $q, is_scalar($obtenido) ? $obtenido : '—',
           $ok ? 'OK' : "FALLA (esperado: $esperado)");
}

foreach ($casos as $archivo => [$familia, $esp]) {
    $ruta = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $archivo;
    echo "\n=== $archivo  [$familia] ===\n";
    if (!is_file($ruta)) { echo "   (no está en la carpeta, se omite)\n"; continue; }

    $h = csv_sat_abrir($ruta);
    if (!$h) { echo "   NO SE PUDO ABRIR\n"; $fallos++; continue; }

    $cab = csv_sat_encabezado($h);
    comprueba('líneas de preámbulo', $esp['preambulo'], count($cab['preambulo']));
    comprueba('columnas', $esp['columnas'], count($cab['columnas']));
    comprueba('fecha dentro del archivo', $esp['fecha'], $cab['fecha_archivo'] ?? '(ninguna)');

    $filas = 0; $validos = 0; $malos = [];
    $situaciones = []; $tipos = [];
    $ejemplo = null;

    foreach (csv_sat_filas($h, $familia, $cab['columnas']) as $r) {
        $filas++;
        if ($r['rfc_valido']) $validos++; else $malos[$r['rfc_motivo']] = ($malos[$r['rfc_motivo']] ?? 0) + 1;
        if ($r['situacion'] !== null && $r['situacion'] !== '') $situaciones[$r['situacion']] = ($situaciones[$r['situacion']] ?? 0) + 1;
        elseif ($r['situacion'] === '') $situaciones['(vacío)'] = ($situaciones['(vacío)'] ?? 0) + 1;
        if ($r['tipo_persona']) $tipos[$r['tipo_persona']] = ($tipos[$r['tipo_persona']] ?? 0) + 1;
        if ($ejemplo === null && $r['rfc_valido']) $ejemplo = $r;
    }
    fclose($h);

    comprueba('filas leídas', $esp['filas'], $filas);
    comprueba('RFC válidos', null, $validos);
    if ($malos) {
        echo "      RFC descartados:\n";
        foreach ($malos as $m => $c) printf("         %-14s %d\n", $m, $c);
    }
    if ($tipos) {
        echo "      tipo de persona:  ";
        foreach ($tipos as $t => $c) echo "$t=$c  ";
        echo "\n";
    }
    if ($situaciones) {
        echo "      situaciones:\n";
        arsort($situaciones);
        foreach ($situaciones as $s => $c) printf("         %-22s %d\n", $s, $c);
    }
    if ($ejemplo) {
        echo "      ejemplo: {$ejemplo['rfc']} ({$ejemplo['tipo_persona']}) "
           . mb_substr($ejemplo['nombre'], 0, 46) . "\n";
    }
}

/* --- acentos: la prueba de que la conversión de codificación funciona ------ */
echo "\n=== codificación ===\n";
$ruta = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'l69b.csv';
if (is_file($ruta)) {
    $h = csv_sat_abrir($ruta);
    $cab = csv_sat_encabezado($h);
    $conAcento = null;
    foreach (csv_sat_filas($h, 'art69b', $cab['columnas']) as $r) {
        if (preg_match('/[ÁÉÍÓÚÑáéíóúñ]/u', $r['nombre'])) { $conAcento = $r['nombre']; break; }
    }
    fclose($h);
    $pruebas++;
    $ok = $conAcento !== null && mb_check_encoding($conAcento, 'UTF-8');
    if (!$ok) $fallos++;
    printf("      %-26s %-30s %s\n", 'acentos en UTF-8', mb_substr((string)$conAcento, 0, 30), $ok ? 'OK' : 'FALLA');
}

/* --- normalización de RFC: casos que sí están en los archivos -------------- */
echo "\n=== normalización de RFC ===\n";
foreach ([
    ['AAA080808HL8',     true,  'M', ''],
    ['AAAA730727JE3',    true,  'F', ''],
    [' aaa080808hl8 ',   true,  'M', ''],
    ['AAA-080808-HL8',   true,  'M', ''],
    // Regresión: la Ñ ocupa dos bytes. Con strlen daba 14 y se descartaba.
    // En el listado de 69-B hay 98 RFC así.
    ['ÑAÑ140114GY4',     true,  'M', ''],
    ['ÑEX121116KM4',     true,  'M', ''],
    // Regresión: el SAT tacha registros por la LFPDPPP. No son basura.
    ['XXXXXXXXXXXX',     false, null, 'suprimido'],
    ['',                 false, null, 'vacio'],
    ['ABC123',           false, null, 'longitud_6'],
    ['AAA080808HL8XXXX', false, null, 'longitud_16'],
] as [$e, $vEsp, $tEsp, $mEsp]) {
    $r = csv_sat_rfc($e);
    $pruebas++;
    $ok = $r['valido'] === $vEsp && $r['tipo'] === $tEsp && ($mEsp === '' || $r['motivo'] === $mEsp);
    if (!$ok) $fallos++;
    printf("      %-20s -> %-16s %-3s %-12s %s\n", "'$e'", $r['rfc'] ?: '(vacío)',
           $r['tipo'] ?? '-', $r['motivo'] ?: '-', $ok ? 'OK' : "FALLA (esperaba $mEsp)");
}

echo "\n" . ($fallos ? "RESULTADO: $fallos fallo(s) de $pruebas comprobaciones\n"
                     : "RESULTADO: $pruebas comprobaciones, todas OK\n");
exit($fallos ? 1 : 0);
