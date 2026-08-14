<?php
/* ============================================================================
   migracion.php — pone al día una base creada con una versión anterior.

   esquema.sql usa CREATE TABLE IF NOT EXISTS, que no toca una tabla que ya
   existe: en el servidor, donde las tablas se crearon antes, las columnas
   nuevas no aparecerían nunca y las páginas fallarían con "Unknown column".

   Por eso esto se ejecuta solo, desde los tres caminos por los que se puede
   entrar: el cron, el panel y la pantalla de alertas. Después de la primera
   vez son dos consultas al catálogo y nada más.
   ============================================================================ */

require_once __DIR__ . '/bd.php';

/**
 * Tablas que faltan. Se llama antes que nada porque una columna no se puede
 * añadir a una tabla que no existe.
 *
 * El agujero que esto tapa: la puesta en marcha ejecuta esquema.sql una vez, y
 * el botón que lo hace solo aparece mientras faltan tablas. Cuando después se
 * añade una tabla NUEVA al esquema —pasó con dof_tipo_cambio y dof_corridas—
 * ninguna instalación existente la crea nunca, y la pantalla que la usa muere
 * con «Base table or view not found». Como todo el esquema es CREATE TABLE IF
 * NOT EXISTS, volver a ejecutarlo entero es inofensivo.
 */
function migrar_tablas_pendientes(bool $revisarDeNuevo = false): array
{
    // El estático evita un SHOW TABLES por llamada. $revisarDeNuevo existe para
    // que las pruebas puedan comprobar la función de verdad y no una imitación.
    static $hecho = null;
    if ($hecho !== null && !$revisarDeNuevo) return $hecho;

    $esperadas = ['snapshots', 'estatus', 'eventos', 'bitacora', 'ingestas',
                  'dof_tipo_cambio', 'dof_corridas'];
    $hay = [];
    foreach (bd()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) $hay[$t] = true;

    $faltan = array_values(array_filter($esperadas, fn($t) => !isset($hay[$t])));
    if (!$faltan) return $hecho = [];

    bd_ejecutar_sql(__DIR__ . '/../esquema.sql');
    return $hecho = ['tabla(s) creada(s): ' . implode(', ', $faltan)];
}

function migrar_columnas_pendientes(): array
{
    static $hecho = null;
    if ($hecho !== null) return $hecho;   // una vez por petición, no más

    $hizo = migrar_tablas_pendientes();

    if (bd_asegurar_columna('snapshots', 'linea_base', 'TINYINT(1) NOT NULL DEFAULT 0')) {
        $hizo[] = 'columna snapshots.linea_base';

        /* La primera descarga de cada lista es el punto de partida, no
           movimiento. Sus altas no son noticia: se marcan para que no salgan
           en alertas ni disparen avisos. Solo al crear la columna: después,
           quien marca la línea base es la propia ingesta. */
        $marcadas = bd()->exec("
            UPDATE snapshots s
            JOIN (SELECT lista, MIN(id) primero FROM snapshots GROUP BY lista) p
              ON p.lista = s.lista AND p.primero = s.id
            SET s.linea_base = 1
            WHERE s.linea_base = 0");

        if ($marcadas) {
            $bajados = bd()->exec("
                UPDATE eventos e JOIN snapshots s ON s.id = e.snapshot_id
                SET e.prioridad = 0
                WHERE s.linea_base = 1 AND e.prioridad > 0");
            $hizo[] = sprintf('línea base: %d carga(s) inicial(es), %s evento(s) dejan de ser alerta',
                              $marcadas, number_format((int)$bajados));
        }
    }

    if (bd_asegurar_columna('eventos', 'avisado_en', 'DATETIME NULL DEFAULT NULL')) {
        $hizo[] = 'columna eventos.avisado_en';

        /* Lo ya cargado no se avisa: son movimientos que el despacho ya conoce
           y un correo con miles de líneas al desplegar no ayuda a nadie. Se
           dan por avisados; los que lleguen a partir de ahora, no. */
        $sellados = bd()->exec("UPDATE eventos SET avisado_en = NOW() WHERE avisado_en IS NULL");
        $hizo[] = sprintf('%s evento(s) anteriores se dan por avisados', number_format((int)$sellados));
    }

    if (bd_asegurar_columna('snapshots', 'avisado_en', 'DATETIME NULL DEFAULT NULL')) {
        $hizo[] = 'columna snapshots.avisado_en';
        // Lo ya cargado no se avisa: son publicaciones que el despacho ya tiene
        // delante. Solo interesan las que lleguen a partir de ahora.
        $s = bd()->exec("UPDATE snapshots SET avisado_en = NOW() WHERE avisado_en IS NULL");
        $hizo[] = sprintf('%s publicación(es) anteriores se dan por avisadas', number_format((int)$s));
    }

    return $hecho = $hizo;
}
