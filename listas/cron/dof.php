<?php
/* ============================================================================
   dof.php — envoltorio de consola del tipo de cambio del DOF.

   Uso:  php listas/cron/dof.php sync            los últimos 15 días
         php listas/cron/dof.php sync 40         una ventana más ancha
         php listas/cron/dof.php backfill 2021   toda la serie desde ese año
         php listas/cron/dof.php estado          cómo está el latido
         php listas/cron/dof.php consulta 2026-08-14

         php listas/cron/dof.php eq-sync            equivalencias mensuales
         php listas/cron/dof.php eq-estado          periodos y huecos
         php listas/cron/dof.php eq-consulta 2026-07 Japon

   El backfill trae la serie del propio indicador, no de una copia: el DOF
   entrega los 8,723 días de toda su historia en una sola petición de 1.72 MB.
   ============================================================================ */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/lib/dof_tc.php';
require_once __DIR__ . '/lib/migracion.php';

$cmd = $argv[1] ?? 'sync';
$arg = $argv[2] ?? null;
$eco = function (string $l) { echo "$l\n"; };

try {
    bd_ejecutar_sql(__DIR__ . '/esquema.sql');
    migrar_columnas_pendientes();

    switch ($cmd) {
        case 'sync':
            $r = dof_tc_sincronizar((int)($arg ?: 15), $eco);
            echo $r['ok'] ? "\nTerminado.\n" : "\nTerminado con avisos: {$r['motivo']}\n";
            exit($r['ok'] ? 0 : 1);

        case 'backfill':
            /* Una sola petición para toda la serie. Medido: 1.72 MB en 1.3 s
               y 8,723 filas, de las cuales el filtro de cordura descarta las
               anteriores a 2001 —los pesos viejos— y se dice cuántas. */
            $desdeAnio = (int)($arg ?: 2021);
            $desde = "01/01/$desdeAnio";
            $hasta = date('d/m/Y');
            echo "Trayendo la serie completa de $desde a $hasta...\n";
            $d = dof_tc_descargar($desde, $hasta);
            if ($d['error'] !== '') { fwrite(STDERR, "FALLO: {$d['error']}\n"); exit(2); }
            $n = dof_tc_guardar($d['filas']);
            dof_tc_registrar_corrida($desde, $hasta, count($d['filas']), $n, $d['fuera_rango'],
                $d['filas'] ? end($d['filas'])['fecha'] : null, (bool)$d['filas'], 'backfill');
            printf("  %s filas leídas · %s nuevas · %d fuera del rango de cordura · %s bytes\n",
                   number_format(count($d['filas'])), number_format($n), $d['fuera_rango'],
                   number_format($d['bytes']));
            if ($d['fuera_rango']) {
                echo "  (las descartadas son de antes de 2001: pesos viejos, fuera del rango 10-40)\n";
            }
            break;

        case 'eq-sync':
            /* Equivalencias mensuales. La nota del mes M sale entre los días 4
               y 7 de M+1, así que correrlo alrededor del 8 la encuentra. */
            require_once __DIR__ . '/lib/dof_eq.php';
            $r = dof_eq_sincronizar($arg ?: '2021-01', null, $eco);
            printf("\n%d periodo(s) nuevo(s), %d error(es).\n", $r['nuevos'], $r['errores']);
            exit($r['errores'] ? 1 : 0);

        case 'eq-estado':
            require_once __DIR__ . '/lib/dof_eq.php';
            $e = dof_eq_estado();
            if (!$e['hay']) { echo "Todavía no hay ninguna tabla mensual cargada.\n"; exit(1); }
            printf("Equivalencias: %s a %s · %d periodos · %s monedas\n",
                   $e['primero'], $e['ultimo'], $e['periodos'], number_format($e['monedas']));
            printf("Huecos: %s\n", $e['faltan'] ? implode(', ', $e['faltan']) : 'ninguno');
            exit($e['faltan'] ? 1 : 0);

        case 'eq-consulta':
            require_once __DIR__ . '/lib/dof_eq.php';
            $per = $arg ?: date('Y-m');
            $que = $argv[3] ?? '';
            if ($que === '') { fwrite(STDERR, "Uso: eq-consulta AAAA-MM pais_o_moneda\n"); exit(2); }
            $filas = dof_eq_consultar($per, $que);
            if (!$filas) { echo "Sin coincidencias para «$que» en $per.\n"; exit(1); }
            foreach ($filas as $f) {
                printf("  %-24s %-24s %s USD%s\n", $f['pais'], $f['moneda'],
                       $f['equivalencia_usd'], $f['por_mil'] ? ' por MIL unidades' : '');
            }
            break;

        case 'estado':
            $e = dof_tc_estado();
            if (!$e['hay']) { echo "Todavía no hay ningún tipo de cambio cargado.\n"; exit(1); }
            printf("Último publicado: %s = %s  (hace %d día%s)\n",
                   $e['ultima'], $e['valor'], $e['dias'], $e['dias'] === 1 ? '' : 's');
            printf("Serie: %s días\n", number_format($e['total']));
            printf("Latido: %s\n", $e['vivo'] ? 'VIVO' : 'PARADO — lleva demasiado sin moverse');
            if ($e['corrida']) {
                printf("Última corrida: %s · %s\n", $e['corrida']['corrida_en'], $e['corrida']['detalle']);
            }
            exit($e['vivo'] ? 0 : 1);

        case 'consulta':
            if (!$arg) { fwrite(STDERR, "Falta la fecha (AAAA-MM-DD).\n"); exit(2); }
            $p = dof_tc_publicado($arg);
            $f = dof_tc_fiscal($arg);
            printf("Publicado el %s: %s\n", $arg, $p ?? '(ese día no hubo publicación)');
            printf("Para una operación del %s se aplica: %s (publicado el %s)\n",
                   $arg, $f['valor'] ?? '—', $f['fecha_publicacion'] ?? '—');
            break;

        default:
            fwrite(STDERR, "Órdenes: sync | backfill | estado | consulta\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FALLO: ' . $e->getMessage() . "\n");
    exit(2);
}
