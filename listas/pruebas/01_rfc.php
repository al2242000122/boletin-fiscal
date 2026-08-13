<?php
/* ============================================================================
   01_rfc.php — csv_sat_rfc(), la puerta por la que pasa todo.

   Si esta función se equivoca, un contribuyente desaparece del sistema sin que
   nadie lo note: no sale en la consulta, no sale en el lote, no genera alerta.
   Es el punto donde un fallo silencioso hace más daño.
   ============================================================================ */

require_once __DIR__ . '/../cron/lib/csv_sat.php';

/* --- la Ñ ocupa dos bytes ---------------------------------------------
   Con strlen, ÑAÑ140114GY4 mide 14 y se descartaba por longitud. En el
   listado del 69-B hay 98 RFC así. */
$n = csv_sat_rfc('ÑAÑ140114GY4');
comprobar('RFC con Ñ es válido',            true, $n['valido']);
comprobar('RFC con Ñ se clasifica moral',   'M',  $n['tipo']);
comprobar('ÑEX121116KM4 también',           true, csv_sat_rfc('ÑEX121116KM4')['valido']);

/* --- registros que el SAT publica tachados ---------------------------
   91 filas del listado completo vienen con el RFC como XXXXXXXXXXXX y el
   nombre "Información suprimida en cumplimiento de la LFPDPPP". No son basura:
   hay que distinguirlos de un RFC mal escrito. */
$s = csv_sat_rfc('XXXXXXXXXXXX');
comprobar('suprimido no es válido',                false,       $s['valido']);
comprobar('suprimido se distingue por su motivo',  'suprimido', $s['motivo']);

/* --- normalización ---------------------------------------------------- */
comprobar('quita guiones y espacios', 'AAA080808HL8', csv_sat_rfc(' aaa-08 08 08.hl8 ')['rfc']);
comprobar('sube a mayúsculas',        'AAA080808HL8', csv_sat_rfc('aaa080808hl8')['rfc']);

/* --- longitudes -------------------------------------------------------
   12 = moral, 13 = física. La distinción no es cosmética: las personas físicas
   son datos personales bajo la LFPDPPP y las morales no. */
comprobar('12 caracteres es persona moral',  'M', csv_sat_rfc('AAA080808HL8')['tipo']);
comprobar('13 caracteres es persona física', 'F', csv_sat_rfc('AAAA010101AAA')['tipo']);

comprobar('RFC vacío da motivo "vacio"',     'vacio',       csv_sat_rfc('')['motivo']);
comprobar('RFC corto da la longitud',        'longitud_3',  csv_sat_rfc('ABC')['motivo']);
comprobar('RFC de 16 no pasa',               false,         csv_sat_rfc('AAAA0101010101AA')['valido']);
comprobar('forma inválida se detecta',       'formato',     csv_sat_rfc('123456789012')['motivo']);
comprobar('el & es válido en razones sociales', true,       csv_sat_rfc('N&F0105097F2')['valido']);
