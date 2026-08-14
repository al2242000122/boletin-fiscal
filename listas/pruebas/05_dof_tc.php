<?php
/* ============================================================================
   05_dof_tc.php — el tipo de cambio del DOF.

   Aquí se fija la regla del artículo 20 del CFF, que es donde un error de un
   solo carácter mueve todos los importes en pesos sin que nada truene.
   ============================================================================ */

require_once __DIR__ . '/../cron/lib/dof_tc.php';

/* --- el parseo, sin red ------------------------------------------------
   El caso guardado es una petición real del 27/03/2026 al 10/04/2026: cruza
   Semana Santa, así que trae el hueco entre el 1 y el 6 de abril. */
$html = @file_get_contents(caso('dof_indicador_semana_santa.html'));
if ($html === false) {
    omitir('parseo del indicador', 'falta casos/dof_indicador_semana_santa.html');
} else {
    $r = dof_tc_interpretar($html);
    comprobar('lee las 9 publicaciones del rango', 9, count($r['filas']));
    comprobar('nada cae fuera del rango de cordura', 0, $r['fuera_rango']);
    comprobar('la primera es la del 27 de marzo', '2026-03-27', $r['filas'][0]['fecha']);
    comprobar('y su valor se conserva con los seis decimales', '17.795700', $r['filas'][0]['valor']);
    comprobar('la última es la del 10 de abril', '2026-04-10', $r['filas'][8]['fecha']);

    // Semana Santa 2026: jueves 2 y viernes 3 de abril no se publica.
    $fechas = array_column($r['filas'], 'fecha');
    comprobar_que('no inventa filas en Semana Santa',
        !in_array('2026-04-02', $fechas, true) && !in_array('2026-04-03', $fechas, true),
        'fechas leídas: ' . implode(' ', $fechas));
    comprobar_que('las filas salen ordenadas por fecha',
        $fechas === array_values(array_unique($fechas)) && $fechas == (function ($f) { sort($f); return $f; })($fechas),
        implode(' ', $fechas));
}

/* --- el filtro de cordura cuenta, no calla -----------------------------
   Descartar en silencio es el fallo que este proyecto ya ha pagado dos veces:
   el byte que cortaba el CSD y las listas vacías del artículo 69. */
$falso = '<td>01-02-2026</td><td>17.500000</td>'
       . '<td>02-02-2026</td><td>1234.567800</td>'   // fuera de rango: se cuenta
       . '<td>03-02-2026</td><td>0.123400</td>';     // fuera de rango: se cuenta
$r = dof_tc_interpretar($falso);
comprobar('se queda solo con lo verosímil', 1, count($r['filas']));
comprobar("y dice cuántas descartó",       2, $r['fuera_rango']);

/* --- una fecha imposible no entra -------------------------------------- */
$r = dof_tc_interpretar('<td>31-02-2026</td><td>17.500000</td>');
comprobar('31 de febrero no existe y no se guarda', 0, count($r['filas']));

/* ==========================================================================
   La regla del artículo 20. Necesita base de datos.
   ========================================================================== */
if (getenv('BD_HOST') === false) {
    omitir('regla del artículo 20 sobre la serie', 'sin BD_HOST');
    return;
}

bd_ejecutar_sql(__DIR__ . '/../cron/esquema.sql');

/* --- una tabla nueva se crea sola en una base que ya existía --------------
   Este agujero ha mordido tres veces. La puesta en marcha ejecuta esquema.sql
   una vez y su botón solo aparece mientras faltan tablas; cuando después se
   añade una tabla nueva al esquema, ninguna instalación existente la crea, y
   la pantalla que la usa muere con «Base table or view not found». Pasó en
   producción con dof_tipo_cambio el 14/08/2026. */
require_once __DIR__ . '/../cron/lib/migracion.php';
bd()->exec("DROP TABLE IF EXISTS dof_corridas");
$antes = in_array('dof_corridas', bd()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN), true);
comprobar('la tabla no está antes de migrar', false, $antes);

$hizo = migrar_tablas_pendientes(true);   // true: no reutilizar el estático
comprobar('y después de migrar sí está', true,
    in_array('dof_corridas', bd()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN), true));
comprobar_que('y la migración dice qué creó',
    count($hizo) === 1 && str_contains($hizo[0], 'dof_corridas'),
    'devolvió: ' . var_export($hizo, true));

bd()->exec("DELETE FROM dof_tipo_cambio WHERE fecha BETWEEN '2020-01-01' AND '2020-12-31'");

/* Serie de juguete en un año que la serie real no cubre, para no pisarla.
   Se salta el 2 y el 3 de enero de 2020 —viernes y fin de semana— a propósito. */
$st = bd()->prepare("INSERT INTO dof_tipo_cambio (fecha,valor,fuente,ingresado_en)
                     VALUES (?,?,'PRUEBA',NOW()) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
foreach ([['2020-01-06','18.100000'], ['2020-01-07','18.200000'],
          ['2020-01-08','18.300000'], ['2020-01-13','18.400000']] as [$f, $v]) {
    $st->execute([$f, $v]);
}

comprobar('publicado devuelve el de ese día exacto', '18.200000', dof_tc_publicado('2020-01-07'));
comprobar('y null si ese día no hubo publicación', null, dof_tc_publicado('2020-01-11'));

/* La regla: para una operación del día O se aplica el publicado el día hábil
   bancario ANTERIOR. Estrictamente anterior: si esto fuera «<=» se aplicaría
   el del mismo día, que es el error de un carácter que mueve todos los
   importes sin romper nada. */
$f = dof_tc_fiscal('2020-01-07');
comprobar('para operar el 7 se usa lo publicado el 6', '2020-01-06', $f['fecha_publicacion']);
comprobar('y no lo publicado el mismo 7',              '18.100000', $f['valor']);

/* Fin de semana y puente: no vale «el día anterior», vale «la última
   publicación anterior». El 11 y 12 son sábado y domingo. */
comprobar('el sábado 11 usa lo del viernes 8',  '2020-01-08', dof_tc_fiscal('2020-01-11')['fecha_publicacion']);
comprobar('el lunes 13 también usa lo del 8',   '2020-01-08', dof_tc_fiscal('2020-01-13')['fecha_publicacion']);
comprobar('tras el hueco, el 14 usa lo del 13', '2020-01-13', dof_tc_fiscal('2020-01-14')['fecha_publicacion']);

/* Antes del arranque no se inventa un cero: se devuelve null y quien llame
   decide. Un cero silencioso se convierte en un importe en cero que nadie ve. */
comprobar('antes del primer dato devuelve null, no cero', null, dof_tc_fiscal('2020-01-06'));

bd()->exec("DELETE FROM dof_tipo_cambio WHERE fuente = 'PRUEBA'");
