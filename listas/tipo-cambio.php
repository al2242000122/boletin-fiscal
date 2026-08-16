<?php
/* ============================================================================
   tipo-cambio.php — el tipo de cambio del DOF, por fecha.

   Dos preguntas distintas que se confunden todo el tiempo, y por eso se
   contestan las dos por separado en la misma pantalla:

   · ¿Qué publicó el DOF ese día?
   · ¿Qué tipo se aplica a una operación de ese día? — que es el del día hábil
     bancario ANTERIOR, por el artículo 20 del CFF.

   La fecha que guarda la base es la de PUBLICACIÓN en el DOF, no la de
   determinación. La razón, con la medición que la sostiene, está encima de la
   tabla en cron/esquema.sql.
   ============================================================================ */

require __DIR__ . '/../acceso.php';
acceso_exigir();
require_once __DIR__ . '/cabecera.php';
require_once __DIR__ . '/cron/lib/dof_tc.php';
require_once __DIR__ . '/cron/lib/migracion.php';

// Bases creadas antes de que existiera este módulo: se crean las tablas solas.
try { migrar_columnas_pendientes(); } catch (Throwable $e) { /* se dirá abajo */ }

$fecha = trim((string)($_GET['fecha'] ?? ''));
if ($fecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = '';

/* Traer la serie desde aquí, que es donde uno se entera de que falta. Antes el
   mensaje mandaba a la consola: en un hosting compartido eso equivale a no
   hacerlo nunca. */
$avisoCarga = '';
if (($_POST['accion'] ?? '') === 'traer'
    && hash_equals($_SESSION['token'] ?? '', (string)($_POST['token'] ?? ''))) {
    @set_time_limit(0);
    try {
        $r = dof_tc_traer_serie(2021);
        $avisoCarga = $r['ok']
            ? sprintf('Listo: %s días cargados, hasta el %s.',
                      number_format($r['leidas']), $r['ultima'])
            : 'No se pudo traer la serie: ' . $r['motivo'];
    } catch (Throwable $e) {
        $avisoCarga = 'No se pudo traer la serie: ' . $e->getMessage();
    }
}

$estado = dof_tc_estado();
$publicado = $aplicable = null;
if ($estado['hay'] && $fecha !== '') {
    $publicado  = dof_tc_publicado($fecha);
    $aplicable  = dof_tc_fiscal($fecha);
}

$serie = [];
if ($estado['hay']) {
    $serie = bd()->query("SELECT fecha, valor FROM dof_tipo_cambio
                          ORDER BY fecha DESC LIMIT 30")->fetchAll();
}

function tc_fecha_larga(?string $iso): string
{
    static $m = [1=>'enero','febrero','marzo','abril','mayo','junio','julio',
                 'agosto','septiembre','octubre','noviembre','diciembre'];
    if (!$iso) return '—';
    $t = strtotime($iso);
    return $t ? sprintf('%d de %s de %d', (int)date('j',$t), $m[(int)date('n',$t)], (int)date('Y',$t)) : $iso;
}
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tipo de cambio · International Support Services</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="../css/portal.css">
<style>
  .latido{ display:flex; gap:24px; flex-wrap:wrap; align-items:center;
           padding:16px 22px; border-radius:10px; margin-bottom:20px; }
  .latido.vivo{ background:#F2F8F4; border:1px solid #BBDDC7; }
  .latido.muerto{ background:#FBEEF0; border:1px solid #E6B9BF; }
  .latido b{ color:var(--navy); }
</style>
</head>
<body>

<?php cabecera('tipo-cambio', 'Tipo de cambio', 'Diario Oficial de la Federación'); ?>

<main class="seccion">
  <div class="contenedor">

    <?php if ($avisoCarga): ?>
      <div class="<?= str_starts_with($avisoCarga, 'Listo') ? 'latido vivo' : 'alerta' ?>"
           style="margin-bottom:16px"><?= esc($avisoCarga) ?></div>
    <?php endif; ?>

    <?php if (!$estado['hay']): ?>
      <div class="cifra destacada">
        <h2>La serie todavía no está cargada</h2>
        <p style="margin:6px 0 16px;font-size:14.5px;line-height:1.65;color:var(--mut)">
          Son <b>1.414 días desde enero de 2021</b>, y el DOF los entrega de una
          sola vez: unos 320 KB y cinco segundos. Solo hace falta pulsarlo una
          vez — a partir de ahí la tarea programada lo mantiene al día.
        </p>
        <form method="post" action="tipo-cambio.php">
          <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
          <input type="hidden" name="accion" value="traer">
          <button class="btn-buscar">Traer la serie completa</button>
        </form>
      </div>
    <?php else: ?>

      <div class="latido <?= $estado['vivo'] ? 'vivo' : 'muerto' ?>">
        <div>
          <?php if ($estado['vivo']): ?>
            <b>Al día.</b> Último publicado el
            <b><?= esc(tc_fecha_larga($estado['ultima'])) ?></b>:
            <b><?= esc(rtrim(rtrim($estado['valor'], '0'), '.')) ?></b> pesos por dólar.
          <?php else: ?>
            <b>Lleva <?= (int)$estado['dias'] ?> días sin actualizarse.</b>
            El DOF publica todos los días hábiles, así que esto significa que la
            tarea programada dejó de correr.
          <?php endif; ?>
          <div class="tenue"><?= number_format($estado['total']) ?> días en la serie.</div>
        </div>
      </div>

      <form class="buscador" method="get" action="tipo-cambio.php">
        <div class="campo">
          <label for="fecha">Fecha de la operación</label>
          <input type="date" id="fecha" name="fecha" value="<?= esc($fecha) ?>"
                 max="<?= esc(date('Y-m-d')) ?>">
        </div>
        <button class="btn-buscar">Consultar</button>
      </form>

      <?php if ($fecha !== ''): ?>
        <div class="cifra destacada">
          <h2>El que se aplica a una operación del <?= esc(tc_fecha_larga($fecha)) ?></h2>
          <?php if ($aplicable): ?>
            <div class="valor"><?= esc(rtrim(rtrim($aplicable['valor'], '0'), '.')) ?></div>
            <div class="pie">
              Publicado en el DOF el <b><?= esc(tc_fecha_larga($aplicable['fecha_publicacion'])) ?></b>.
              El artículo 20 del Código Fiscal manda usar el publicado el día hábil
              bancario inmediato anterior, y ese es este.
            </div>
          <?php else: ?>
            <div class="valor">—</div>
            <div class="pie">
              No hay ninguna publicación anterior a esa fecha en la serie cargada,
              que empieza en enero de 2021. No se rellena con un cero: no lo sabemos.
            </div>
          <?php endif; ?>
        </div>

        <div class="cifra">
          <h2>Lo que el DOF publicó ese mismo día</h2>
          <?php if ($publicado !== null): ?>
            <div class="valor" style="font-size:26px"><?= esc(rtrim(rtrim($publicado, '0'), '.')) ?></div>
            <div class="pie">
              Ojo: este es el que se aplica a las operaciones del <b>día hábil
              siguiente</b>, no a las de hoy. Es el tipo que Banco de México
              determinó el día hábil anterior a esta fecha.
            </div>
          <?php else: ?>
            <div class="valor" style="font-size:26px">—</div>
            <div class="pie">Ese día el DOF no publicó: fue sábado, domingo o inhábil.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <h2 class="seccion-titulo seccion-titulo-2">Últimas publicaciones</h2>
      <table class="datos">
        <tr><th>Publicado en el DOF</th><th>Pesos por dólar</th><th>Se aplica a operaciones del</th></tr>
        <?php foreach ($serie as $i => $s): ?>
          <tr>
            <td><?= esc(tc_fecha_larga($s['fecha'])) ?></td>
            <td class="mono"><?= esc(rtrim(rtrim($s['valor'], '0'), '.')) ?></td>
            <td class="tenue">
              <?php /* El siguiente día hábil es la fila anterior de la serie, que
                       viene ordenada de más nueva a más vieja. */
                    echo $i > 0 ? esc(tc_fecha_larga($serie[$i - 1]['fecha']))
                                : 'el próximo día hábil'; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>

      <p class="nota-legal">
        <b>Las dos fechas no son la misma.</b> La columna de la izquierda es la
        fecha de <b>publicación en el DOF</b>. El tipo que aparece ahí lo
        determinó Banco de México el día hábil anterior, y se aplica a las
        operaciones del día hábil siguiente. Medido: la edición del DOF del
        14 de agosto de 2026 dice «el tipo de cambio obtenido el día de hoy fue
        de $17.0530» y va firmada el 13 de agosto.
        Fuente: indicador 158 del DOF, <span class="mono">dof.gob.mx</span>.
      </p>

    <?php endif; ?>
  </div>
</main>

<?php pie(); ?>

</body>
</html>
