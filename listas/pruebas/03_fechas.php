<?php
/* ============================================================================
   03_fechas.php — las dos fechas, que no son la misma.

   La del preámbulo del CSV es la que se cita como evidencia. La del servidor
   es la única que existe en el Artículo 69. Confundirlas fecha mal una
   constancia, y una constancia mal fechada no sirve de nada.
   ============================================================================ */

require_once __DIR__ . '/../cron/lib/csv_sat.php';
require_once __DIR__ . '/../cron/lib/fuentes.php';

/* --- la fecha de dentro del archivo ----------------------------------- */
$p = ['Información actualizada al 31 de mayo de 2026; los listados a que se hace mención…'];
comprobar('lee la fecha del preámbulo',        '2026-05-31', csv_sat_fecha_preambulo($p));
comprobar('con acentos en el mes',             '2026-12-01',
          csv_sat_fecha_preambulo(['Información actualizada al 1 de diciembre de 2026']));
comprobar('sin preámbulo devuelve null',       null, csv_sat_fecha_preambulo([]));
comprobar('preámbulo sin fecha devuelve null', null, csv_sat_fecha_preambulo(['Listado completo']));

/* --- fechas de columna ------------------------------------------------- */
comprobar('DD/MM/AAAA',                 '2018-06-01', csv_sat_fecha('01/06/2018'));
comprobar('sin cero delante',           '2019-03-05', csv_sat_fecha('5/3/2019'));
comprobar('fecha imposible es null',    null,         csv_sat_fecha('31/02/2020'));
comprobar('texto suelto es null',       null,         csv_sat_fecha('no aplica'));
comprobar('vacío es null',              null,         csv_sat_fecha(''));

/* --- Last-Modified -----------------------------------------------------
   Medido el 13/08/2026: ante "Tue, 17 Jun 2026" —el 17 es miércoles— PHP no da
   error, avanza hasta el martes siguiente y devuelve el 23. Seis días de
   diferencia, en silencio, en la fecha que se cita como evidencia. Por eso se
   descarta el nombre del día antes de interpretarla. */
$cab = fn($v) => "HTTP/1.1 200 OK\r\nContent-Type: text/csv\r\nLast-Modified: $v\r\n";

comprobar('Last-Modified normal',  '2026-03-12 12:44:12',
          fuentes_fecha_servidor($cab('Thu, 12 Mar 2026 12:44:12 GMT')));
comprobar('día de la semana equivocado NO desplaza la fecha', '2026-06-17 08:14:00',
          fuentes_fecha_servidor($cab('Tue, 17 Jun 2026 08:14:00 GMT')));
comprobar('día de la semana correcto da lo mismo',            '2026-06-17 08:14:00',
          fuentes_fecha_servidor($cab('Wed, 17 Jun 2026 08:14:00 GMT')));
comprobar('sin nombre de día',     '2026-06-17 08:14:00',
          fuentes_fecha_servidor($cab('17 Jun 2026 08:14:00 GMT')));
comprobar('cabecera ilegible es null', null, fuentes_fecha_servidor($cab('basura')));
comprobar('sin cabecera es null',      null, fuentes_fecha_servidor("HTTP/1.1 200 OK\r\n"));

/* --- que no se confundan ----------------------------------------------
   El caso real medido: el archivo dice "al 31 de mayo" y el servidor lo publicó
   el 17 de junio. Diecisiete días. */
$dentro = csv_sat_fecha_preambulo(['Información actualizada al 31 de mayo de 2026']);
$fuera  = substr((string)fuentes_fecha_servidor($cab('Wed, 17 Jun 2026 08:14:00 GMT')), 0, 10);
comprobar_que('las dos fechas del mismo archivo no coinciden', $dentro !== $fuera,
              "dentro=$dentro fuera=$fuera");
