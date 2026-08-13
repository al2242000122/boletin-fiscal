<?php
/* ============================================================================
   correr.php — las pruebas de la herramienta de listas.

   Uso:  php listas/pruebas/correr.php
         BD_HOST=127.0.0.1 BD_PUERTO=3399 BD_NOMBRE=pruebas BD_USUARIO=root \
             php listas/pruebas/correr.php      (incluye el simulacro)

   No cubren todo el módulo a propósito: cubren los sitios donde ya se ha roto,
   que están medidos y documentados en docs/ESQUEMAS.md. La Ñ que ocupa dos
   bytes, los registros que el SAT publica tachados, el registro partido en dos
   líneas, el día de la semana que descoloca la fecha, y la cadena completa de
   deltas, que de otro modo solo se puede probar esperando meses a que el SAT
   publique otra vez.

   El riesgo que atajan no es que el código esté mal hoy: es que una
   refactorización futura lo rompa sin que nadie se entere.
   ============================================================================ */

// Nunca por web. listas/pruebas no está bloqueado en .htaccess y esto crea
// tablas: servido por HTTP sería una forma cómoda de estropear la base.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/lib.php';

$archivos = glob(__DIR__ . '/[0-9][0-9]_*.php');
sort($archivos);

echo "\n";
foreach ($archivos as $a) {
    grupo(ucfirst(str_replace('_', ' ', preg_replace('/^\d+_|\.php$/', '', basename($a)))));
    require $a;
}

exit(resumen());
