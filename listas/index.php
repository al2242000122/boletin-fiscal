<?php
/* ============================================================================
   listas/index.php — panel de la herramienta de listas del SAT.

   Existe para que la puesta en marcha no exija consola: desde aquí se crean
   las tablas y se lanza la primera actualización con un botón. El
   mantenimiento posterior lo hace el cron.
   ============================================================================ */

require __DIR__ . '/../acceso.php';
acceso_exigir();

/* --- estado ------------------------------------------------------------- */
$hayConfig = is_file(__DIR__ . '/../privado/config.php');
$conecta = false; $errorBD = ''; $tablas = []; $resumen = null; $ingestas = [];

if ($hayConfig) {
    try {
        require_once __DIR__ . '/cron/lib/bd.php';
        $tablas = bd()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $conecta = true;
        if (in_array('estatus', $tablas, true)) {
            $resumen = bd()->query("
                SELECT lista, COUNT(*) total,
                       SUM(situacion='Presunto') presuntos,
                       SUM(tipo_persona='M') morales
                FROM estatus WHERE vigente = 1 GROUP BY lista ORDER BY lista")->fetchAll();
            $ingestas = bd()->query("SELECT * FROM ingestas ORDER BY lista")->fetchAll();
        }
    } catch (Throwable $e) { $errorBD = $e->getMessage(); }
}
$hayTablas = in_array('estatus', $tablas, true);

/* --- acciones ------------------------------------------------------------ */
$salida = ''; $accion = $_POST['accion'] ?? '';

if ($accion && hash_equals($_SESSION['token'] ?? '', $_POST['token'] ?? '')) {
    @set_time_limit(0);
    ob_start();
    try {
        if ($accion === 'crear' && $hayConfig) {
            $n = bd_ejecutar_sql(__DIR__ . '/cron/esquema.sql');
            echo "Listo: $n sentencias ejecutadas. Las tablas ya existen.\n";
        } elseif ($accion === 'actualizar' && $hayTablas) {
            // Solo 69-B desde la web: es lo que interesa y entra en el tiempo
            // que da el servidor. Las listas grandes del Art. 69 las trae el
            // cron, que no tiene ese límite.
            require_once __DIR__ . '/cron/lib/ingestor.php';
            $r = ingestar(['grupo' => 'art69b'], false, function ($l) { echo "$l\n"; });
            echo "\n" . ($r['errores'] ? "Terminado con {$r['errores']} fallo(s). "
                                       : "Terminado. ") . "Eventos nuevos: {$r['eventos']}\n";
        }
    } catch (Throwable $e) {
        echo "\nFALLO: " . $e->getMessage() . "\n";
    }
    $salida = ob_get_clean();
    // Volver a leer el estado después de actuar
    header('Location: index.php?hecho=1');
    $_SESSION['salida_listas'] = $salida;
    exit;
}
if (!empty($_SESSION['salida_listas'])) { $salida = $_SESSION['salida_listas']; unset($_SESSION['salida_listas']); }

$paso = !$hayConfig ? 1 : (!$conecta ? 1 : (!$hayTablas ? 2 : 3));
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Listas del SAT — International Support Services, S.C.</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="../css/portal.css">
<style>
  .paso{ display:flex; gap:16px; padding:20px 22px; border:1px solid var(--rule);
         border-radius:10px; background:#fff; margin-bottom:14px; }
  .paso.hecho{ background:#F2F8F4; border-color:#BBDDC7; }
  .paso.toca{ border-color:var(--acc); box-shadow:0 6px 20px rgba(10,37,64,.10); }
  .paso-num{ flex:0 0 30px; height:30px; display:grid; place-items:center; border-radius:50%;
             background:var(--soft); color:var(--navy); font-weight:700; font-size:13px; }
  .paso.hecho .paso-num{ background:#2E8B57; color:#fff; }
  .paso.toca .paso-num{ background:var(--acc); color:#fff; }
  .paso h2{ margin:0 0 6px; font-size:16px; color:var(--navy); }
  .paso p{ margin:0 0 10px; font-size:13.5px; line-height:1.6; color:var(--mut); }
  .paso code{ background:var(--soft); padding:2px 6px; border-radius:4px;
              font-size:12.5px; color:var(--navy); }
  .btn-accion{ font:inherit; font-size:14px; font-weight:600; color:#fff; background:var(--acc);
               border:0; border-radius:7px; padding:10px 18px; cursor:pointer; }
  .btn-accion:hover{ background:#17608F; }
  .btn-accion[disabled]{ background:#B9C6D3; cursor:not-allowed; }
  pre.salida{ background:#0E1116; color:#D6DEE8; padding:16px; border-radius:8px;
              font-size:12.5px; line-height:1.55; overflow-x:auto; white-space:pre-wrap; }
  table.datos{ width:100%; border-collapse:collapse; font-size:13.5px; }
  table.datos th{ text-align:left; font-size:11px; letter-spacing:.08em; text-transform:uppercase;
                  color:var(--mut); padding:8px 10px; border-bottom:1px solid var(--rule); }
  table.datos td{ padding:8px 10px; border-bottom:1px solid var(--rule); }
  .alerta{ padding:12px 14px; border-radius:8px; background:#FBEEF0; border:1px solid #E6B9BF;
           color:#8C2733; font-size:13.5px; line-height:1.55; margin-bottom:14px; }
</style>
</head>
<body>

<header class="cabecera">
  <div class="contenedor">
    <div class="marca">
      <div class="marca-sigla" aria-hidden="true">ISS</div>
      <div class="marca-nombre">
        <b>Listas del SAT</b>
        <span>Artículo 69 · 69-B · 69-B Bis</span>
      </div>
    </div>
    <p class="cabecera-contacto"><a href="consulta.php">Consultar RFC</a> · <a href="../index.php">Portal</a></p>
  </div>
</header>

<main class="seccion">
  <div class="contenedor">

    <?php if ($errorBD): ?>
      <div class="alerta"><b>No se pudo conectar a la base de datos.</b><br>
        <?= esc($errorBD) ?></div>
    <?php endif; ?>

    <?php if (!empty($GLOBALS['bd_config_sucia'])): ?>
      <div class="alerta">
        <b>El archivo <code>privado/config.php</code> tiene texto fuera de las etiquetas PHP.</b><br>
        Funciona, pero conviene limpiarlo: cualquier carácter antes de
        <code>&lt;?php</code> se imprime en la página y puede romper los enlaces
        internos. Ábrelo en el Administrador de archivos y asegúrate de que los
        primeros cinco caracteres del archivo sean exactamente
        <code>&lt;?php</code>, sin nada delante.<br><br>
        Se está imprimiendo esto: <em><?= esc($GLOBALS['bd_config_sucia']) ?>…</em>
      </div>
    <?php endif; ?>

    <h2 class="seccion-titulo">Puesta en marcha</h2>
    <p class="seccion-nota">Tres pasos, una sola vez. Después esto se actualiza solo.</p>

    <!-- PASO 1 -->
    <div class="paso <?= $hayConfig && $conecta ? 'hecho' : 'toca' ?>">
      <div class="paso-num"><?= $hayConfig && $conecta ? '✓' : '1' ?></div>
      <div>
        <h2>Datos de la base de datos</h2>
        <?php if ($hayConfig && $conecta): ?>
          <p>Configurado y conectando correctamente.</p>
        <?php else: ?>
          <p>Falta el archivo <code>privado/config.php</code> en el servidor, o los
             datos que tiene no son correctos. Este archivo no viaja con el código
             a propósito: lleva la contraseña de la base.</p>
          <p>En el Administrador de archivos de hPanel, entra a
             <code>public_html/privado/</code>, copia
             <code>config.php.ejemplo</code>, renombra la copia a
             <code>config.php</code> y pon dentro los datos de tu base.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- PASO 2 -->
    <div class="paso <?= $hayTablas ? 'hecho' : ($paso === 2 ? 'toca' : '') ?>">
      <div class="paso-num"><?= $hayTablas ? '✓' : '2' ?></div>
      <div>
        <h2>Crear las tablas</h2>
        <?php if ($hayTablas): ?>
          <p>Hechas: <?= count($tablas) ?> tablas (<?= esc(implode(', ', $tablas)) ?>).</p>
        <?php else: ?>
          <p>Se crean solas con este botón. Se puede pulsar varias veces sin
             romper nada: si ya existen, no las toca.</p>
          <form method="post">
            <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
            <input type="hidden" name="accion" value="crear">
            <button class="btn-accion" <?= $conecta ? '' : 'disabled' ?>>Crear las tablas</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- PASO 3 -->
    <div class="paso <?= $resumen ? 'hecho' : ($paso === 3 ? 'toca' : '') ?>">
      <div class="paso-num"><?= $resumen ? '✓' : '3' ?></div>
      <div>
        <h2>Traer las listas del SAT</h2>
        <p>Descarga los listados del artículo 69-B y los carga. Tarda alrededor
           de un minuto. Si el archivo no cambió desde la última vez, no hace
           nada: no se duplica.</p>
        <form method="post">
          <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
          <input type="hidden" name="accion" value="actualizar">
          <button class="btn-accion" <?= $hayTablas ? '' : 'disabled' ?>>Actualizar ahora</button>
        </form>
      </div>
    </div>

    <?php if ($salida): ?>
      <h2 class="seccion-titulo seccion-titulo-2">Resultado</h2>
      <pre class="salida"><?= esc(trim($salida)) ?></pre>
    <?php endif; ?>

    <?php if ($resumen): ?>
      <h2 class="seccion-titulo seccion-titulo-2">Qué hay cargado</h2>
      <table class="datos">
        <tr><th>Lista</th><th>Registros</th><th>Presuntos</th><th>Personas morales</th></tr>
        <?php foreach ($resumen as $r): ?>
          <tr>
            <td><?= esc($r['lista']) ?></td>
            <td><?= number_format((int)$r['total']) ?></td>
            <td><?= number_format((int)$r['presuntos']) ?></td>
            <td><?= number_format((int)$r['morales']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>

      <?php if ($ingestas): ?>
        <h2 class="seccion-titulo seccion-titulo-2">Última actualización</h2>
        <table class="datos">
          <tr><th>Lista</th><th>Último éxito</th><th>Último cambio</th><th>Error</th></tr>
          <?php foreach ($ingestas as $i): ?>
            <tr>
              <td><?= esc($i['lista']) ?></td>
              <td><?= esc($i['ultimo_exito'] ?? '—') ?></td>
              <td><?= esc($i['ultimo_cambio'] ?? '—') ?></td>
              <td><?= esc($i['ultimo_error'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</main>

<footer class="pie">
  <div class="contenedor">
    <p><b>International Support Services, S.C.</b><br>Uso interno del despacho.</p>
    <p><a href="../salir.php">Cerrar sesión</a></p>
  </div>
</footer>

</body>
</html>
