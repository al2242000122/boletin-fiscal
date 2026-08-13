<?php
/* ============================================================================
   ingestor.php — la lógica de ingesta, sin depender de la consola.

   Se separó del script de cron porque aquel terminaba con exit() y no se podía
   reutilizar: al incluirlo desde el panel web mataba la petición. Aquí no hay
   ningún exit ni echo directo — todo sale por el registro que se le pasa.
   ============================================================================ */

require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/fuentes.php';
require_once __DIR__ . '/csv_sat.php';

const RAIZ_ARCHIVO = __DIR__ . '/../../../privado/listas/raw';

/**
 * Ingiere las listas indicadas.
 * $filtro: ['lista' => 'art69b.presuntos'] o ['grupo' => 'art69b'] o []
 * $log: función que recibe cada línea de avance.
 */
function ingestar(array $filtro = [], bool $forzar = false, ?callable $log = null): array
{
    $log ??= function ($t) {};

    $d = fuentes_descubrir();
    if (!$d['ok']) return ['ok' => false, 'motivo' => $d['motivo'], 'eventos' => 0, 'errores' => 1];

    $listas = $d['listas'];
    if (!empty($filtro['lista'])) $listas = array_intersect_key($listas, [$filtro['lista'] => 1]);
    if (!empty($filtro['grupo'])) $listas = array_filter($listas, fn($l) => $l['grupo'] === $filtro['grupo']);
    if (!$listas) return ['ok' => false, 'motivo' => 'Ninguna lista coincide con lo pedido.',
                          'eventos' => 0, 'errores' => 1];

    $eventos = 0; $errores = 0;
    foreach ($listas as $clave => $l) {
        $log("=== $clave · {$l['etiqueta']} ===");
        try {
            $r = ingerir($clave, $l, $forzar, $log);
            $eventos += $r['eventos'];
        } catch (Throwable $e) {
            $errores++;
            $log('   FALLO: ' . $e->getMessage());
            registrar_error($clave, $e->getMessage());
        }
    }
    return ['ok' => $errores === 0, 'motivo' => '', 'eventos' => $eventos, 'errores' => $errores];
}

/* ==========================================================================
   Ingesta de una lista
   ========================================================================== */
function ingerir(string $clave, array $lista, bool $forzar, callable $log): array
{
    $ahora = date('Y-m-d H:i:s');
    bd()->prepare("INSERT INTO ingestas (lista, ultimo_intento) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE ultimo_intento = VALUES(ultimo_intento)")
        ->execute([$clave, $ahora]);

    /* --- descarga a temporal ------------------------------------------- */
    $tmp = tempnam(sys_get_temp_dir(), 'sat');
    $bytes = descargar($lista['url'], $tmp);
    $sha = hash_file('sha256', $tmp);
    $log(sprintf('   descargado  %s bytes  sha %s…', number_format($bytes), substr($sha, 0, 12)));

    /* --- ¿ya lo teníamos? ---------------------------------------------- */
    // Se comprueba procesado_en, no solo que exista el snapshot: si una corrida
    // anterior se cayó a media carga, la fila del snapshot ya estaba puesta y
    // saltárselo dejaría esa lista sin cargar para siempre.
    $st = bd()->prepare("SELECT id, procesado_en FROM snapshots WHERE lista = ? AND sha256 = ?");
    $st->execute([$clave, $sha]);
    $prev = $st->fetch();
    $existente = $prev['id'] ?? null;
    if ($existente && $prev['procesado_en'] !== null && !$forzar) {
        unlink($tmp);
        $log("   sin cambios respecto al snapshot #$existente — no se reprocesa");
        bd()->prepare("UPDATE ingestas SET ultimo_exito=? WHERE lista=?")->execute([$ahora, $clave]);
        return ['eventos' => 0];
    }

    /* --- lectura y archivado ------------------------------------------- */
    $h = csv_sat_abrir($tmp);
    if (!$h) throw new RuntimeException('no se pudo abrir el archivo descargado');
    $cab = csv_sat_encabezado($h);
    if (!$cab['columnas']) throw new RuntimeException('no se encontró el encabezado (¿cambió el formato?)');

    $fechaArchivo = $cab['fecha_archivo'];   // la de dentro; puede ser null en Art. 69
    $carpeta = RAIZ_ARCHIVO . '/' . $clave;
    if (!is_dir($carpeta)) mkdir($carpeta, 0775, true);
    $destino = sprintf('%s/%s_%s.csv', $carpeta, $fechaArchivo ?: date('Y-m-d'), substr($sha, 0, 12));
    copy($tmp, $destino);

    /* --- snapshot ------------------------------------------------------- */
    if ($existente) {
        $snapshotId = (int)$existente;
        bd()->prepare("DELETE FROM eventos WHERE snapshot_id = ?")->execute([$snapshotId]);
    } else {
        bd()->prepare("INSERT INTO snapshots
              (lista,url,sha256,bytes,fecha_archivo,fecha_servidor,ruta_archivo,descargado_en)
              VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$clave, $lista['url'], $sha, $bytes, $fechaArchivo, null,
                       str_replace(RAIZ_ARCHIVO . '/', '', $destino), date('Y-m-d H:i:s')]);
        $snapshotId = (int)bd()->lastInsertId();
    }

    /* --- carga a tabla de trabajo --------------------------------------- */
    bd()->exec("DROP TEMPORARY TABLE IF EXISTS carga");
    bd()->exec("CREATE TEMPORARY TABLE carga (
        rfc VARCHAR(20) NOT NULL, proc_hash CHAR(16) NOT NULL, proc_texto VARCHAR(200) NULL,
        situacion VARCHAR(60) NULL, supuesto VARCHAR(80) NULL, nombre VARCHAR(400) NULL,
        tipo_persona CHAR(1) NULL, entidad VARCHAR(80) NULL, datos LONGTEXT NULL,
        PRIMARY KEY (rfc, proc_hash)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // La colación tiene que ser la MISMA que la de las tablas permanentes. Si
    // se deja al valor por defecto del servidor (general_ci), los JOIN contra
    // estatus fallan con "Illegal mix of collations" y la ingesta no carga.

    $ins = bd()->prepare("INSERT IGNORE INTO carga
        (rfc,proc_hash,proc_texto,situacion,supuesto,nombre,tipo_persona,entidad,datos)
        VALUES (?,?,?,?,?,?,?,?,?)");

    $filas = $validas = $suprimidas = $descartadas = 0;
    bd()->beginTransaction();
    foreach (csv_sat_filas($h, grupo_familia($lista['grupo']), $cab['columnas']) as $f) {
        $filas++;
        if ($f['rfc_motivo'] === 'suprimido') { $suprimidas++; continue; }
        if (!$f['rfc_valido']) { $descartadas++; continue; }

        // Identificador del procedimiento: el oficio global de presunción.
        // No cambia cuando el expediente avanza, y por eso sirve de clave.
        $oficio = trim((string)($f['crudo'][4] ?? ''));
        $proc   = $lista['grupo'] === 'art69' ? '' : substr(hash('sha256', $oficio), 0, 16);

        $ins->execute([$f['rfc'], $proc, mb_substr($oficio, 0, 200), $f['situacion'],
                       $f['supuesto'], mb_substr((string)$f['nombre'], 0, 400),
                       $f['tipo_persona'], $f['entidad'],
                       json_encode($f['crudo'], JSON_UNESCAPED_UNICODE)]);
        $validas++;
    }
    bd()->commit();
    fclose($h);
    unlink($tmp);
    $log(sprintf('   filas %s · cargadas %s · suprimidas %d · descartadas %d',
                 number_format($filas), number_format($validas), $suprimidas, $descartadas));

    /* --- diferencias -----------------------------------------------------
       Si la lista no tenía nada cargado, esto es la línea base: todo entraría
       como alta y no sería noticia. Se registra como tal para que no ensucie
       las alertas ni dispare avisos por correo. */
    $st = bd()->prepare("SELECT COUNT(*) FROM estatus WHERE lista = ? AND vigente = 1");
    $st->execute([$clave]);
    $esLineaBase = ((int)$st->fetchColumn() === 0);

    $hoy = $fechaArchivo ?: date('Y-m-d');
    $ev = aplicar_diferencias($clave, $snapshotId, $hoy);

    if ($esLineaBase) {
        bd()->prepare("UPDATE snapshots SET linea_base = 1 WHERE id = ?")->execute([$snapshotId]);
        bd()->prepare("UPDATE eventos SET prioridad = 0 WHERE snapshot_id = ?")->execute([$snapshotId]);
        $ev['urgentes'] = 0;
        $log('   (carga inicial: se registra como línea base, sin alertas)');
    }

    bd()->prepare("UPDATE snapshots SET filas=?, filas_validas=?, procesado_en=? WHERE id=?")
        ->execute([$filas, $validas, date('Y-m-d H:i:s'), $snapshotId]);
    bd()->prepare("INSERT INTO ingestas (lista,ultimo_intento,ultimo_exito,ultimo_cambio,ultimo_sha256,ultimo_error)
                   VALUES (?,?,?,?,?,NULL)
                   ON DUPLICATE KEY UPDATE ultimo_exito=VALUES(ultimo_exito),
                     ultimo_cambio=VALUES(ultimo_cambio), ultimo_sha256=VALUES(ultimo_sha256),
                     ultimo_error=NULL")
        ->execute([$clave, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $sha]);

    $log(sprintf('   altas %d · cambios %d · bajas %d%s',
                 $ev['altas'], $ev['cambios'], $ev['bajas'],
                 $ev['urgentes'] ? "   [{$ev['urgentes']} URGENTES]" : ''));

    return ['eventos' => $ev['altas'] + $ev['cambios'] + $ev['bajas']];
}


/* ==========================================================================
   Diferencias entre lo cargado y lo vigente. Todo en SQL: para Firmes son
   250 000 filas y no caben cómodamente en memoria de PHP.
   ========================================================================== */
function aplicar_diferencias(string $lista, int $snapshotId, string $fecha): array
{
    $ahora = date('Y-m-d H:i:s');

    /* 1. CAMBIOS: existe en ambos lados pero cambió la situación o el supuesto */
    bd()->prepare("
        INSERT INTO eventos (rfc,lista,proc_hash,tipo,situacion_anterior,situacion_nueva,
                             nombre,tipo_persona,prioridad,detectado_en,snapshot_id)
        SELECT e.rfc, e.lista, e.proc_hash, 'cambio', e.situacion, c.situacion,
               c.nombre, c.tipo_persona,
               CASE WHEN c.situacion = 'Definitivo' THEN 2 ELSE 1 END,
               ?, ?
        FROM estatus e
        JOIN carga c ON c.rfc = e.rfc AND c.proc_hash = e.proc_hash
        WHERE e.lista = ? AND e.vigente = 1
          AND NOT (e.situacion <=> c.situacion AND e.supuesto <=> c.supuesto)
    ")->execute([$ahora, $snapshotId, $lista]);
    $cambios = bd()->query("SELECT ROW_COUNT()")->fetchColumn();

    /* 2. BAJAS: estaba vigente y ya no viene en el archivo */
    bd()->prepare("
        INSERT INTO eventos (rfc,lista,proc_hash,tipo,situacion_anterior,situacion_nueva,
                             nombre,tipo_persona,prioridad,detectado_en,snapshot_id)
        SELECT e.rfc, e.lista, e.proc_hash, 'baja', e.situacion, NULL,
               e.nombre, e.tipo_persona, 1, ?, ?
        FROM estatus e
        LEFT JOIN carga c ON c.rfc = e.rfc AND c.proc_hash = e.proc_hash
        WHERE e.lista = ? AND e.vigente = 1 AND c.rfc IS NULL
    ")->execute([$ahora, $snapshotId, $lista]);
    $bajas = bd()->query("SELECT ROW_COUNT()")->fetchColumn();

    /* 3. ALTAS: viene en el archivo y no había NINGUNA fila vigente.
          Va ANTES de cerrar, a propósito. Si se calculara después, los
          expedientes que solo cambiaron de situación aparecerían además como
          altas —porque su fila acaba de cerrarse— y se avisaría dos veces del
          mismo hecho. Medido: 5 cambios generaban 5 altas de más. */
    bd()->prepare("
        INSERT INTO eventos (rfc,lista,proc_hash,tipo,situacion_anterior,situacion_nueva,
                             nombre,tipo_persona,prioridad,detectado_en,snapshot_id)
        SELECT c.rfc, ?, c.proc_hash, 'alta', NULL, c.situacion, c.nombre, c.tipo_persona,
               CASE WHEN c.situacion IN ('Presunto','Definitivo') THEN 2 ELSE 0 END,
               ?, ?
        FROM carga c
        LEFT JOIN estatus e
               ON e.rfc = c.rfc AND e.proc_hash = c.proc_hash AND e.lista = ? AND e.vigente = 1
        WHERE e.id IS NULL
    ")->execute([$lista, $ahora, $snapshotId, $lista]);
    $altas = bd()->query("SELECT ROW_COUNT()")->fetchColumn();

    /* 4. Cerrar en estatus todo lo que cambió o desapareció */
    bd()->prepare("
        UPDATE estatus e
        LEFT JOIN carga c ON c.rfc = e.rfc AND c.proc_hash = e.proc_hash
        SET e.valido_hasta = ?, e.vigente = NULL, e.snapshot_hasta = ?
        WHERE e.lista = ? AND e.vigente = 1
          AND (c.rfc IS NULL OR NOT (e.situacion <=> c.situacion AND e.supuesto <=> c.supuesto))
    ")->execute([$fecha, $snapshotId, $lista]);

    /* 5. Abrir las filas nuevas en estatus */
    bd()->prepare("
        INSERT INTO estatus (rfc,lista,proc_hash,proc_texto,situacion,supuesto,nombre,
                             tipo_persona,entidad,datos,valido_desde,vigente,snapshot_desde)
        SELECT c.rfc, ?, c.proc_hash, c.proc_texto, c.situacion, c.supuesto, c.nombre,
               c.tipo_persona, c.entidad, c.datos, ?, 1, ?
        FROM carga c
        LEFT JOIN estatus e
               ON e.rfc = c.rfc AND e.proc_hash = c.proc_hash AND e.lista = ? AND e.vigente = 1
        WHERE e.id IS NULL
    ")->execute([$lista, $fecha, $snapshotId, $lista]);

    $urg = bd()->prepare("SELECT COUNT(*) FROM eventos WHERE snapshot_id = ? AND prioridad = 2");
    $urg->execute([$snapshotId]);

    return ['altas' => (int)$altas, 'cambios' => (int)$cambios,
            'bajas' => (int)$bajas, 'urgentes' => (int)$urg->fetchColumn()];
}


/* ------------------------------------------------------------------ auxiliares */

function grupo_familia(string $grupo): string
{
    return $grupo === 'art69' ? 'art69' : ($grupo === 'art69b_bis' ? 'art69b_bis' : 'art69b');
}

function descargar(string $url, string $destino): int
{
    $f = fopen($destino, 'w');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $f, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300,
        CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_USERAGENT => FUENTES_UA, CURLOPT_FAILONERROR => true,
    ]);
    $ok  = curl_exec($ch);
    $err = curl_error($ch);
    $cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($f);
    if (!$ok) throw new RuntimeException("descarga fallida (HTTP $cod) $err");
    return (int)filesize($destino);
}

function registrar_error(string $lista, string $msg): void
{
    try {
        bd()->prepare("INSERT INTO ingestas (lista, ultimo_intento, ultimo_error) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE ultimo_error = VALUES(ultimo_error)")
            ->execute([$lista, date('Y-m-d H:i:s'), mb_substr($msg, 0, 400)]);
    } catch (Throwable $e) { /* si falla el registro del error, no tapar el error real */ }
}
