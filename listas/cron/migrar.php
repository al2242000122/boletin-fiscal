<?php
/* ============================================================================
   migrar.php — crea o actualiza las tablas.
   Uso:  php listas/cron/migrar.php
   Se puede correr las veces que haga falta: todo es CREATE TABLE IF NOT EXISTS.
   ============================================================================ */
require __DIR__ . '/lib/migracion.php';

try {
    $n = bd_ejecutar_sql(__DIR__ . '/esquema.sql');
    echo "Sentencias ejecutadas: $n\n";

    foreach (migrar_columnas_pendientes() as $paso) echo "Aplicado: $paso\n";
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
