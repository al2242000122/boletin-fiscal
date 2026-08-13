<?php
/* ============================================================================
   lib.php — lo mínimo para comprobar cosas.

   No hay PHPUnit ni lo va a haber: no hay composer en Hostinger y para lo que
   se prueba aquí sobra. Cuatro funciones y un contador.
   ============================================================================ */

$GLOBALS['pruebas'] = ['bien' => 0, 'mal' => 0, 'omitidas' => 0, 'fallos' => []];

const PRUEBAS_CASOS = __DIR__ . '/casos';

function grupo(string $titulo): void
{
    echo "\n\033[1m$titulo\033[0m\n";
}

/** Compara valores. Los arrays se comparan en orden. */
function comprobar(string $que, $esperado, $obtenido): void
{
    $ok = $esperado === $obtenido;
    anotar($ok, $que, $ok ? '' : sprintf('esperado %s · obtenido %s',
        var_export($esperado, true), var_export($obtenido, true)));
}

/** Para condiciones que no son una igualdad. */
function comprobar_que(string $que, bool $condicion, string $detalle = ''): void
{
    anotar($condicion, $que, $detalle);
}

/** Lo que no se puede probar aquí se dice, no se calla. */
function omitir(string $que, string $porque): void
{
    $GLOBALS['pruebas']['omitidas']++;
    echo "  \033[33m—\033[0m  $que \033[2m($porque)\033[0m\n";
}

function anotar(bool $ok, string $que, string $detalle): void
{
    if ($ok) {
        $GLOBALS['pruebas']['bien']++;
        echo "  \033[32m✓\033[0m  $que\n";
    } else {
        $GLOBALS['pruebas']['mal']++;
        $GLOBALS['pruebas']['fallos'][] = $que . ($detalle ? " — $detalle" : '');
        echo "  \033[31m✗  $que\033[0m\n";
        if ($detalle !== '') echo "     \033[31m$detalle\033[0m\n";
    }
}

function caso(string $archivo): string
{
    return PRUEBAS_CASOS . '/' . $archivo;
}

/** Devuelve el código de salida: 0 si todo bien, 1 si algo falló. */
function resumen(): int
{
    $p = $GLOBALS['pruebas'];
    echo "\n" . str_repeat('─', 60) . "\n";
    if ($p['mal'] === 0) {
        echo "\033[32mTODO BIEN\033[0m · {$p['bien']} comprobaciones";
    } else {
        echo "\033[31mFALLARON {$p['mal']}\033[0m de " . ($p['bien'] + $p['mal']) . " comprobaciones";
    }
    if ($p['omitidas']) echo " · {$p['omitidas']} omitidas";
    echo "\n";

    foreach ($p['fallos'] as $f) echo "  \033[31m· $f\033[0m\n";
    echo "\n";
    return $p['mal'] === 0 ? 0 : 1;
}
