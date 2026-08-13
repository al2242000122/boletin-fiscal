<?php
/* ============================================================================
   descubrir.php — comprueba dónde están hoy los archivos del SAT.

   Uso:   php listas/cron/descubrir.php            informe legible
          php listas/cron/descubrir.php --json     salida para máquinas
          php listas/cron/descubrir.php --sin-red  no consulta tamaños (más rápido)

   Es la primera pieza del ingestor y también sirve de alarma: si el SAT vuelve
   a mover los archivos, esto lo dice en vez de dejar que el sistema sirva
   datos viejos sin enterarse.
   ============================================================================ */

require __DIR__ . '/lib/fuentes.php';

$json   = in_array('--json', $argv, true);
$sinRed = in_array('--sin-red', $argv, true);

$d = fuentes_descubrir();

if (!$d['ok']) {
    if ($json) { echo json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n"; exit(2); }
    fwrite(STDERR, "FALLO: {$d['motivo']}\n");
    exit(2);
}

/* Cabeceras de cada archivo: tamaño y fecha del servidor. */
if (!$sinRed) {
    foreach ($d['listas'] as $clave => &$l) {
        $h = fuentes_pedir($l['url'], true);
        $l['http']   = $h['codigo'];
        $l['bytes']  = $h['bytes'];
        $l['fecha_servidor'] = fuentes_fecha_servidor($h['cabeceras']);
        $l['alcanzable'] = $h['ok'];
    }
    unset($l);
}

if ($json) { echo json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n"; exit(0); }

/* ------------------------------------------------------------- informe */

$grupos = ['art69' => 'ARTÍCULO 69', 'art69b' => 'ARTÍCULO 69-B', 'art69b_bis' => 'ARTÍCULO 69-B BIS'];
echo "Índice consultado: " . FUENTES_INDICE . "\n";
echo "Enlaces encontrados en la página: {$d['total_enlaces']}\n";

foreach ($grupos as $g => $titulo) {
    echo "\n== $titulo ==\n";
    foreach ($d['listas'] as $clave => $l) {
        if ($l['grupo'] !== $g) continue;
        $tam   = isset($l['bytes']) ? number_format($l['bytes']) . ' B' : '—';
        $fecha = $l['fecha_servidor'] ?? '—';
        $mal   = isset($l['alcanzable']) && !$l['alcanzable'] ? '  <-- NO RESPONDE' : '';
        printf("  %-24s %14s   %s%s\n", $clave, $tam, $fecha, $mal);
        printf("      %s\n", $l['url']);
    }
}

$problemas = 0;

if ($d['faltantes']) {
    $problemas++;
    echo "\n!! LISTAS QUE NO APARECEN EN EL ÍNDICE (" . count($d['faltantes']) . ")\n";
    echo "   El SAT las movió, las renombró o las retiró. Hay que revisar a mano.\n";
    foreach ($d['faltantes'] as $clave => $q) echo "   - $clave: $q\n";
}

if ($d['ambiguas']) {
    echo "\n * Nombres de archivo repetidos en varias rutas (" . count($d['ambiguas']) . ")\n";
    echo "   Se toma la de /Documents_*, que es la que el SAT mantiene al día.\n";
    foreach ($d['ambiguas'] as $clave => $urls) {
        echo "   - $clave\n";
        foreach ($urls as $u) echo "       $u\n";
    }
}

if ($d['desconocidas']) {
    echo "\n * Archivos publicados que el catálogo no contempla (" . count($d['desconocidas']) . ")\n";
    echo "   No es un error: son otras listas del SAT. Se avisan por si interesan.\n";
    foreach ($d['desconocidas'] as $u => $n) echo "   - $n\n";
}

echo "\n" . ($problemas ? "RESULTADO: revisar, hay listas faltantes.\n"
                        : "RESULTADO: las " . count($d['listas']) . " listas del catálogo están localizadas.\n");
exit($problemas ? 1 : 0);
