<?php
/* ============================================================================
   migrar.php — crea o actualiza las tablas.
   Uso:  php listas/cron/migrar.php
   Se puede correr las veces que haga falta: todo es CREATE TABLE IF NOT EXISTS.
   ============================================================================ */
require __DIR__ . '/lib/bd.php';

try {
    $n = bd_ejecutar_sql(__DIR__ . '/esquema.sql');
    echo "Sentencias ejecutadas: $n\n";

    /* --- columnas añadidas después de la primera versión --------------------
       CREATE TABLE IF NOT EXISTS no toca una tabla que ya existe, así que las
       bases creadas antes se quedarían sin la columna. */
    if (bd_asegurar_columna('snapshots', 'linea_base', 'TINYINT(1) NOT NULL DEFAULT 0')) {
        echo "Añadida la columna snapshots.linea_base.\n";
    }

    /* --- marcar la carga inicial -------------------------------------------
       La primera descarga de cada lista no es movimiento: es el punto de
       partida. Sus 28 864 «altas» no son noticia y no deben salir en alertas
       ni disparar avisos. Se marca el snapshot más antiguo de cada lista. */
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
        printf("Línea base: %d snapshot(s) marcados, %s evento(s) dejan de ser alerta.\n",
               $marcadas, number_format((int)$bajados));
    }
    echo "\n";
    foreach (bd()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $filas = bd()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        printf("  %-14s %s filas\n", $t, number_format((int)$filas));
    }
    echo "\nBase lista.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FALLO: " . $e->getMessage() . "\n");
    exit(1);
}
