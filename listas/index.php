<?php
/* ============================================================================
   listas/index.php — administración de la herramienta de listas del SAT.

   Mientras falte algo de la puesta en marcha, guía paso a paso. Una vez
   resuelta, esos pasos desaparecen y la página queda como tablero de estado:
   qué hay cargado, cuándo se actualizó y si el cron está corriendo.
   ============================================================================ */

require __DIR__ . '/../acceso.php';
acceso_exigir();

/* --- estado ------------------------------------------------------------- */
$hayConfig = is_file(__DIR__ . '/../privado/config.php');
$conecta = false; $errorBD = ''; $tablas = []; $resumen = null; $ingestas = [];
$cronUltima = null; $alertas = 0; $hayArt69 = false;

if ($hayConfig) {
    try {
        require_once __DIR__ . '/cron/lib/bd.php';
        $tablas = bd()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $conecta = true;
        if (in_array('estatus', $tablas, true)) {
            // Bases creadas con una versión anterior: se ponen al día solas.
            require_once __DIR__ . '/cron/lib/migracion.php';
            $migrado = migrar_columnas_pendientes();
            $resumen = bd()->query("
                SELECT lista, COUNT(*) total,
                       SUM(situacion='Presunto') presuntos,
                       SUM(tipo_persona='M') morales
                FROM estatus WHERE vigente = 1 GROUP BY lista ORDER BY lista")->fetchAll();
            $ingestas = bd()->query("SELECT * FROM ingestas ORDER BY lista")->fetchAll();
            // La tarea programada deja constancia al terminar. Si hay alguna,
            // sobra explicar cómo darla de alta.
            $cronUltima = bd()->query("SELECT MAX(consultado_en) FROM bitacora
                                       WHERE origen = 'cron'")->fetchColumn() ?: null;
            $alertas = (int)bd()->query("SELECT COUNT(*) FROM eventos e
                                         JOIN snapshots s ON s.id = e.snapshot_id
                                         WHERE s.linea_base = 0")->fetchColumn();
            // Las listas del artículo 69 pesan 20 MB: solo puede haberlas
            // traído el cron. Que estén cargadas es prueba de que corrió.
            $hayArt69 = (bool)bd()->query("SELECT COUNT(*) FROM ingestas
                                           WHERE lista LIKE 'art69.%' AND ultimo_exito IS NOT NULL")
                                  ->fetchColumn();
        }
    } catch (Throwable $e) { $errorBD = $e->getMessage(); }
}
$hayTablas = in_array('estatus', $tablas, true);
$hayDatos  = !empty($resumen);
$listo     = $hayConfig && $conecta && $hayTablas && $hayDatos;

/* ¿corre el cron? Se mira cuánto hace del último intento con éxito. */
$ultimoExito = null;
foreach ($ingestas as $i) {
    if ($i['ultimo_exito'] && (!$ultimoExito || $i['ultimo_exito'] > $ultimoExito)) $ultimoExito = $i['ultimo_exito'];
}
$diasSinCorrer = $ultimoExito ? floor((time() - strtotime($ultimoExito)) / 86400) : null;
$conErrores = array_filter($ingestas, fn($i) => !empty($i['ultimo_error']));

/* Ruta absoluta real para la línea del cron: así no hay que adivinarla. */
$rutaCron = str_replace('\\', '/', realpath(__DIR__ . '/cron/ingesta.php'));

/* --- acciones ------------------------------------------------------------ */
$salida = ''; $accion = $_POST['accion'] ?? '';

if ($accion && hash_equals($_SESSION['token'] ?? '', $_POST['token'] ?? '')) {
    @set_time_limit(0);
    ob_start();
    try {
        if ($accion === 'crear' && $hayConfig) {
            $n = bd_ejecutar_sql(__DIR__ . '/cron/esquema.sql');
            echo "Listo: $n sentencias ejecutadas. Las tablas ya existen.\n";
        } elseif ($accion === 'probar_correo') {
            require_once __DIR__ . '/cron/lib/aviso.php';
            $p = aviso_probar();
            echo ($p['ok'] ? "Correo de prueba enviado: " : "No se pudo enviar: ") . $p['motivo'] . "\n";
            if ($p['ok']) {
                echo "\nSi no llega en unos minutos, revisa la carpeta de correo no deseado.\n";
            }
        } elseif ($accion === 'actualizar' && $hayTablas) {
            // Solo 69-B desde la web: entra en el tiempo que da el servidor.
            // Las listas grandes del Art. 69 las trae el cron, sin ese límite.
            require_once __DIR__ . '/cron/lib/ingestor.php';
            $r = ingestar(['grupo' => 'art69b'], false, function ($l) { echo "$l\n"; });
            echo "\n" . ($r['errores'] ? "Terminado con {$r['errores']} fallo(s). "
                                       : "Terminado. ") . "Eventos nuevos: {$r['eventos']}\n";
        }
    } catch (Throwable $e) {
        echo "\nFALLO: " . $e->getMessage() . "\n";
    }
    $_SESSION['salida_listas'] = ob_get_clean();
    header('Location: index.php');
    exit;
}
if (!empty($_SESSION['salida_listas'])) { $salida = $_SESSION['salida_listas']; unset($_SESSION['salida_listas']); }
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administración · Listas del SAT</title>
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
  code{ background:var(--soft); padding:2px 6px; border-radius:4px;
        font-size:12.5px; color:var(--navy); }
  .btn-accion{ font:inherit; font-size:14px; font-weight:600; color:#fff; background:var(--acc);
               border:0; border-radius:7px; padding:10px 18px; cursor:pointer; }
  .btn-accion:hover{ background:#17608F; }
  .btn-accion[disabled]{ background:#B9C6D3; cursor:not-allowed; }
  pre.salida{ background:#0E1116; color:#D6DEE8; padding:16px; border-radius:8px;
              font-size:12.5px; line-height:1.55; overflow-x:auto; white-space:pre-wrap; }
  table.datos{ width:100%; border-collapse:collapse; font-size:13.5px; background:#fff;
               border:1px solid var(--rule); border-radius:10px; overflow:hidden; }
  table.datos th{ text-align:left; font-size:11px; letter-spacing:.08em; text-transform:uppercase;
                  color:var(--mut); padding:10px 12px; background:#F7F9FB;
                  border-bottom:1px solid var(--rule); }
  table.datos td{ padding:10px 12px; border-bottom:1px solid var(--rule); }
  table.datos tr:last-child td{ border-bottom:0; }
  .alerta{ padding:12px 14px; border-radius:8px; background:#FBEEF0; border:1px solid #E6B9BF;
           color:#8C2733; font-size:13.5px; line-height:1.55; margin-bottom:14px; }
  .aviso{ padding:12px 14px; border-radius:8px; background:#FDF6E3; border:1px solid #E8D9A8;
          color:#7A5D00; font-size:13.5px; line-height:1.55; margin-bottom:14px; }
  .estado{ display:flex; gap:20px; flex-wrap:wrap; align-items:center; justify-content:space-between;
           padding:18px 22px; background:#F2F8F4; border:1px solid #BBDDC7;
           border-radius:10px; margin-bottom:22px; }
  .estado b{ color:var(--navy); font-size:15px; }
  .estado .tenue{ color:var(--mut); font-size:13px; }
  details.avanzado{ margin-top:26px; }
  details.avanzado summary{ cursor:pointer; font-size:13px; color:var(--mut); }
  details.avanzado summary:hover{ color:var(--acc); }
  .comando{ display:block; margin-top:10px; padding:12px 14px; background:#0E1116; color:#D6DEE8;
            border-radius:8px; font-family:ui-monospace,Consolas,monospace; font-size:12.5px;
            overflow-x:auto; white-space:pre; }
</style>
</head>
<body>

<header class="cabecera">
  <div class="contenedor">
    <div class="marca">
      <div class="marca-sigla" aria-hidden="true">ISS</div>
      <div class="marca-nombre">
        <b>Listas del SAT</b>
        <span>Administración</span>
      </div>
    </div>
    <p class="cabecera-contacto">
      <a href="alertas.php">Alertas</a> · <a href="consulta.php">Consultar RFC</a> ·
      <a href="../index.php">Portal</a>
    </p>
  </div>
</header>

<main class="seccion">
  <div class="contenedor">

    <?php if ($errorBD): ?>
      <div class="alerta"><b>No se pudo conectar a la base de datos.</b><br><?= esc($errorBD) ?></div>
    <?php endif; ?>

    <?php if (!empty($GLOBALS['bd_config_sucia'])): ?>
      <div class="aviso">
        <b>El archivo <code>privado/config.php</code> tiene texto fuera de las etiquetas PHP.</b><br>
        Funciona, pero conviene limpiarlo: los primeros cinco caracteres del
        archivo deben ser exactamente <code>&lt;?php</code>, sin nada delante.
        Ahora mismo se intenta imprimir: <em><?= esc($GLOBALS['bd_config_sucia']) ?>…</em>
      </div>
    <?php endif; ?>

    <?php if ($conErrores): ?>
      <div class="alerta"><b>Alguna lista falló en la última actualización.</b><br>
        <?php foreach ($conErrores as $e): ?>
          <?= esc($e['lista']) ?>: <?= esc($e['ultimo_error']) ?><br>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($diasSinCorrer !== null && $diasSinCorrer >= 3): ?>
      <div class="aviso"><b>Llevan <?= (int)$diasSinCorrer ?> días sin actualizarse.</b>
        Si ya configuraste la tarea automática, revisa que siga activa en hPanel.</div>
    <?php endif; ?>


    <?php if ($listo): ?>
      <!-- ============ TODO LISTO: tablero, sin pasos ============ -->
      <div class="estado">
        <div>
          <b>La herramienta está funcionando.</b>
          <div class="tenue">Última actualización:
            <?= $ultimoExito ? esc($ultimoExito) : 'sin registro' ?>
            · <a href="alertas.php"><?= $alertas
                 ? number_format($alertas) . ' movimiento' . ($alertas === 1 ? '' : 's')
                 : 'sin movimientos' ?></a></div>
        </div>
        <form method="post" style="margin:0">
          <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
          <input type="hidden" name="accion" value="actualizar">
          <button class="btn-accion">Actualizar ahora</button>
        </form>
      </div>

    <?php else: ?>
      <!-- ============ FALTA ALGO: guía paso a paso ============ -->
      <h2 class="seccion-titulo">Puesta en marcha</h2>
      <p class="seccion-nota">Tres pasos, una sola vez. Cuando estén los tres,
        esta guía desaparece y queda solo el tablero.</p>

      <div class="paso <?= $hayConfig && $conecta ? 'hecho' : 'toca' ?>">
        <div class="paso-num"><?= $hayConfig && $conecta ? '✓' : '1' ?></div>
        <div>
          <h2>Datos de la base de datos</h2>
          <?php if ($hayConfig && $conecta): ?>
            <p>Configurado y conectando correctamente.</p>
          <?php else: ?>
            <p>Falta <code>privado/config.php</code> en el servidor, o sus datos
               no son correctos. Este archivo no viaja con el código a propósito:
               lleva la contraseña de la base.</p>
            <p>En hPanel → Administrador de archivos, entra a
               <code>public_html/privado/</code>, copia
               <code>config.php.ejemplo</code>, renombra la copia a
               <code>config.php</code> y pon dentro tus datos.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="paso <?= $hayTablas ? 'hecho' : ($conecta ? 'toca' : '') ?>">
        <div class="paso-num"><?= $hayTablas ? '✓' : '2' ?></div>
        <div>
          <h2>Crear las tablas</h2>
          <?php if ($hayTablas): ?>
            <p>Hechas: <?= count($tablas) ?> tablas.</p>
          <?php else: ?>
            <p>Se crean con este botón. Se puede pulsar varias veces sin romper
               nada: si ya existen, no las toca.</p>
            <form method="post">
              <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
              <input type="hidden" name="accion" value="crear">
              <button class="btn-accion" <?= $conecta ? '' : 'disabled' ?>>Crear las tablas</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="paso <?= $hayDatos ? 'hecho' : ($hayTablas ? 'toca' : '') ?>">
        <div class="paso-num"><?= $hayDatos ? '✓' : '3' ?></div>
        <div>
          <h2>Traer las listas del SAT</h2>
          <p>Descarga los listados del artículo 69-B y los carga. Tarda alrededor
             de un minuto. Si el archivo no cambió, no hace nada: no se duplica.</p>
          <form method="post">
            <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
            <input type="hidden" name="accion" value="actualizar">
            <button class="btn-accion" <?= $hayTablas ? '' : 'disabled' ?>>Actualizar ahora</button>
          </form>
        </div>
      </div>
    <?php endif; ?>


    <?php if ($salida): ?>
      <h2 class="seccion-titulo seccion-titulo-2">Resultado de la última acción</h2>
      <pre class="salida"><?= esc(trim($salida)) ?></pre>
    <?php endif; ?>

    <?php if ($resumen): ?>
      <h2 class="seccion-titulo seccion-titulo-2">Qué hay cargado</h2>
      <table class="datos">
        <tr><th>Lista</th><th>Registros</th><th>Presuntos</th><th>Personas morales</th>
            <th>Última actualización</th></tr>
        <?php $porLista = []; foreach ($ingestas as $i) $porLista[$i['lista']] = $i; ?>
        <?php foreach ($resumen as $r): ?>
          <tr>
            <td><?= esc($r['lista']) ?></td>
            <td><?= number_format((int)$r['total']) ?></td>
            <td><?= number_format((int)$r['presuntos']) ?></td>
            <td><?= number_format((int)$r['morales']) ?></td>
            <td class="tenue"><?= esc($porLista[$r['lista']]['ultimo_exito'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>


    <?php if ($listo && !$cronUltima): ?>
      <!-- Instrucciones de alta del cron: se enseñan solo mientras no haya
           corrido ni una vez. En cuanto la tarea deja constancia, este bloque
           desaparece y el comando queda en «detalles técnicos». -->
      <h2 class="seccion-titulo seccion-titulo-2">Actualización automática</h2>
      <p class="seccion-nota">
        El botón de arriba solo trae el artículo 69-B. Las listas del artículo 69
        —Firmes, No localizados, Cancelados— pesan 20 MB cada una y no caben en
        el tiempo que el servidor da a una página web. Para esas hace falta una
        tarea programada, que además mantiene todo al día sin que nadie entre.
      </p>
      <p class="seccion-nota">
        En hPanel → <b>Avanzado → Trabajos cron</b>, crea una tarea que corra
        <b>una vez al día</b> con este comando:
      </p>
      <code class="comando">/usr/bin/php <?= esc($rutaCron) ?></code>
      <p class="seccion-nota" style="margin-top:12px">
        Esa ruta es la de tu servidor, ya resuelta: cópiala tal cual. Si hPanel
        pide la ruta de PHP por separado, usa <code>php</code> y deja lo demás.
      </p>
      <div class="aviso" style="margin-top:14px">
        <?php if ($hayArt69): ?>
          <b>La tarea ya corrió alguna vez.</b>
          Se nota porque las listas del artículo 69 están cargadas y esas solo
          las puede traer el cron. Lo que falta es la constancia con fecha, que
          se empezó a registrar ahora: aparecerá en la próxima corrida y
          entonces este apartado se quita solo.
        <?php else: ?>
          <b>Todavía no hay constancia de que la tarea haya corrido.</b>
          Ojo: la constancia se registra desde esta versión, así que si ya la
          diste de alta, esto es normal y se resolverá en la próxima corrida.
          Si mañana sigue igual, la tarea no está funcionando: revísala en
          hPanel y comprueba que la ruta coincide con la de arriba.
        <?php endif; ?>
      </div>
    <?php elseif ($listo && $cronUltima): ?>
      <p class="seccion-nota" style="margin-top:22px">
        La tarea automática está corriendo: última vez el
        <b><?= esc(substr((string)$cronUltima, 0, 16)) ?></b>.
        El comando, por si hay que volver a darlo de alta, está en los detalles técnicos.
      </p>
    <?php endif; ?>

    <?php if ($listo):
      require_once __DIR__ . '/cron/lib/aviso.php';
      $correos = aviso_destinatarios();
      $pendientes = (int)bd()->query("SELECT COUNT(*) FROM eventos e
                                      JOIN snapshots s ON s.id = e.snapshot_id
                                      WHERE e.prioridad = 2 AND s.linea_base = 0
                                        AND e.avisado_en IS NULL")->fetchColumn(); ?>
      <h2 class="seccion-titulo seccion-titulo-2">Aviso por correo</h2>
      <?php if ($correos): ?>
        <p class="seccion-nota">
          Cuando el SAT publique un RFC nuevo como presunto, o alguno pase a
          definitivo, sale un correo a <b><?= esc(implode(', ', $correos)) ?></b>.
          Solo lo urgente, una vez por movimiento, nunca la carga inicial.
          <?php if ($pendientes): ?>
            Hay <b><?= $pendientes ?></b> sin avisar todavía; salen en la próxima corrida.
          <?php endif; ?>
        </p>
        <form method="post">
          <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
          <input type="hidden" name="accion" value="probar_correo">
          <button class="btn-accion">Enviar un correo de prueba</button>
        </form>
      <?php else: ?>
        <p class="seccion-nota">
          Sin configurar: hoy hay que entrar a mirar las alertas. Para recibirlas,
          abre <code>privado/config.php</code> en el Administrador de archivos y
          añade al final estas dos líneas, con tu dirección:
        </p>
        <code class="comando">const AVISO_CORREO    = 'tucorreo@insusermx.com';
const AVISO_REMITENTE = 'alertas@insusermx.com';</code>
        <p class="seccion-nota" style="margin-top:12px">
          El remitente tiene que ser una dirección del propio dominio; si pones
          un Gmail, el correo sale marcado como falsificado y acaba en spam.
          Después vuelve aquí y manda una prueba.
        </p>
      <?php endif; ?>
    <?php endif; ?>

    <details class="avanzado">
      <summary>Ver detalles técnicos</summary>
      <table class="datos" style="margin-top:12px">
        <tr><th>Comprobación</th><th>Estado</th></tr>
        <tr><td>privado/config.php</td><td><?= $hayConfig ? 'presente' : 'falta' ?></td></tr>
        <tr><td>Conexión a MySQL</td><td><?= $conecta ? 'correcta' : 'sin conexión' ?></td></tr>
        <tr><td>Tablas</td><td><?= $tablas ? esc(implode(', ', $tablas)) : 'ninguna' ?></td></tr>
        <tr><td>Comando del cron</td>
            <td class="tenue">/usr/bin/php <?= esc($rutaCron) ?></td></tr>
        <tr><td>Última corrida automática</td>
            <td class="tenue"><?= $cronUltima ? esc($cronUltima) : 'todavía ninguna' ?></td></tr>
        <tr><td>Aviso por correo</td>
            <td class="tenue"><?= $listo && !empty($correos)
                 ? esc(implode(', ', $correos)) . ' · desde ' . esc(aviso_remitente())
                 : 'sin configurar' ?></td></tr>
        <tr><td>Zona horaria</td><td class="tenue"><?= esc(date_default_timezone_get()) ?>
            · ahora son las <?= esc(date('H:i')) ?></td></tr>
      </table>
    </details>

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
