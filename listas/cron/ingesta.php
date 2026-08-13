<?php
/* ============================================================================
   ingesta.php — envoltorio de consola. La lógica está en lib/ingestor.php,
   que también usa el panel web.

   Uso:  php listas/cron/ingesta.php                    todas las del catálogo
         php listas/cron/ingesta.php art69b.presuntos   solo esa
         php listas/cron/ingesta.php --grupo art69b     solo ese grupo
         php listas/cron/ingesta.php --forzar           reprocesa aunque no cambie

   Es idempotente: si el archivo no cambió (mismo sha256) no se vuelve a
   procesar, y si se fuerza, el resultado es el mismo, no se duplica nada.
   ============================================================================ */

require __DIR__ . '/lib/ingestor.php';

$args   = array_slice($argv, 1);
$forzar = in_array('--forzar', $args, true);
$args   = array_values(array_filter($args, fn($a) => $a !== '--forzar'));

$filtro = [];
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--grupo') { $filtro['grupo'] = $args[++$i] ?? ''; }
    elseif ($args[$i] !== '' && $args[$i][0] !== '-') { $filtro['lista'] = $args[$i]; }
}

$r = ingestar($filtro, $forzar, function (string $linea) { echo "$linea\n"; });

if (!$r['ok'] && $r['motivo']) { fwrite(STDERR, "FALLO: {$r['motivo']}\n"); exit(2); }
echo "\n" . ($r['errores'] ? "Terminado con {$r['errores']} fallo(s). " : "Terminado. ")
   . "Eventos nuevos: {$r['eventos']}\n";
exit($r['errores'] ? 1 : 0);
