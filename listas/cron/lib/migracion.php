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

function migrar_columnas_pendientes(): array
{
    static $hecho = null;
    if ($hecho !== null) return $hecho;   // una vez por petición, no más

    $hizo = [];

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

    return $hecho = $hizo;
}
