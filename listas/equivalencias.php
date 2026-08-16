<?php
/* ============================================================================
   equivalencias.php — las ~69 monedas contra el dólar, mes a mes.

   Para convertir una operación en moneda distinta del dólar hacen falta dos
   saltos: moneda → USD con esta tabla mensual, y USD → MXN con el tipo de
   cambio diario. La pantalla enseña los dos.

   Lo que no se puede perder de vista: hay monedas cuya equivalencia viene
   expresada POR MIL UNIDADES. Aquí se dice con todas sus letras y se hace la
   cuenta ya hecha, porque el número de la llamada al pie cambia entre
   publicaciones y no se puede confiar en él.
   ============================================================================ */

require __DIR__ . '/../acceso.php';
acceso_exigir();
require_once __DIR__ . '/cron/lib/dof_eq.php';
require_once __DIR__ . '/cron/lib/dof_tc.php';
require_once __DIR__ . '/cron/lib/migracion.php';

try { migrar_columnas_pendientes(); } catch (Throwable $e) { /* se dirá abajo */ }

/* --- traer lo que falte, desde aquí ------------------------------------
   Por trozos: la serie entera son 67 notas y unos 53 segundos medidos, que en
   hosting compartido es pedirle problemas a una sola petición. */
const EQ_POR_TANDA = 15;
$avisoCarga = '';
if (($_POST['accion'] ?? '') === 'traer'
    && hash_equals($_SESSION['token'] ?? '', (string)($_POST['token'] ?? ''))) {
    @set_time_limit(0);
    try {
        $r = dof_eq_sincronizar('2021-01', null, null, EQ_POR_TANDA);
        $avisoCarga = $r['nuevos'] === 0
            ? 'No había nada nuevo que traer.'
            : sprintf('Listo: %d mes(es) cargados.%s', $r['nuevos'],
                      !empty($r['quedan']) ? ' Quedan más: vuelve a pulsarlo.' : '');
        if ($r['errores']) $avisoCarga .= sprintf(' %d nota(s) fallaron.', $r['errores']);
    } catch (Throwable $e) {
        $avisoCarga = 'No se pudo traer: ' . $e->getMessage();
    }
}

$estado = dof_eq_estado();

$periodo = trim((string)($_GET['periodo'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) $periodo = $estado['ultimo'] ?? date('Y-m');
$busca = trim((string)($_GET['q'] ?? ''));

$filas = [];
if ($estado['hay']) {
    $filas = $busca !== ''
        ? dof_eq_consultar($periodo, $busca)
        : bd()->query("SELECT pais, moneda, nota, por_mil, equivalencia_usd
                       FROM dof_equivalencias WHERE periodo = "
                       . bd()->quote($periodo) . " ORDER BY pais, moneda")->fetchAll();
}

$periodos = $estado['hay']
    ? bd()->query("SELECT periodo, fecha_publicacion FROM dof_publicaciones
                   ORDER BY periodo DESC")->fetchAll()
    : [];

/* El tipo de cambio del día para poder rematar la conversión a pesos. */
$tcHoy = dof_tc_estado();

function eq_mes_largo(string $p): string
{
    static $m = [1=>'enero','febrero','marzo','abril','mayo','junio','julio',
                 'agosto','septiembre','octubre','noviembre','diciembre'];
    [$y, $mm] = array_map('intval', explode('-', $p));
    return ($m[$mm] ?? $p) . ' de ' . $y;
}
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Equivalencias de monedas — International Support Services, S.C.</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="../css/portal.css">
<style>
  .buscador{ display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;
             padding:20px 22px; background:#fff; border:1px solid var(--rule);
             border-radius:10px; margin-bottom:20px; }
  .campo{ display:flex; flex-direction:column; gap:5px; }
  .campo label{ font-size:11px; font-weight:700; letter-spacing:.08em;
                text-transform:uppercase; color:var(--mut); }
  .campo input, .campo select{ font:inherit; font-size:14.5px; padding:9px 11px;
     border:1px solid var(--rule); border-radius:7px; color:var(--ink); background:#fff; }
  .btn-buscar{ font:inherit; font-size:14.5px; font-weight:600; color:#fff;
     background:var(--acc); border:0; border-radius:7px; padding:10px 20px; cursor:pointer; }
  .limpiar{ font-size:13px; color:var(--mut); text-decoration:none; padding:10px 4px; }

  .cifra{ padding:22px 24px; background:#fff; border:1px solid var(--rule);
          border-radius:10px; margin-bottom:14px; }
  .cifra.destacada{ border-left:4px solid var(--acc); }
  .cifra h2{ margin:0 0 6px; font-size:13px; letter-spacing:.06em;
             text-transform:uppercase; color:var(--mut); }
  .estado{ display:flex; gap:20px; flex-wrap:wrap; padding:16px 22px; background:#F2F8F4;
           border:1px solid #BBDDC7; border-radius:10px; margin-bottom:20px; }
  .estado b{ color:var(--navy); }
  table.datos{ width:100%; border-collapse:collapse; font-size:13.5px; background:#fff;
               border:1px solid var(--rule); border-radius:10px; overflow:hidden; }
  table.datos th{ text-align:left; font-size:11px; letter-spacing:.08em; text-transform:uppercase;
                  color:var(--mut); padding:11px 12px; background:#F7F9FB;
                  border-bottom:1px solid var(--rule); }
  table.datos td{ padding:10px 12px; border-bottom:1px solid var(--rule); }
  table.datos tr:last-child td{ border-bottom:0; }
  tr.pormil{ background:#FFF9F0; }
  .mono{ font-family:ui-monospace,Consolas,monospace; }
  .tenue{ color:var(--mut); font-size:12.5px; }
  .etq{ display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px;
        font-weight:700; background:#FDF0D5; color:#8A5B00; }
  .alerta{ padding:12px 14px; border-radius:8px; background:#FBEEF0; border:1px solid #E6B9BF;
           color:#8C2733; font-size:13.5px; margin-bottom:16px; }
  .aviso{ padding:12px 14px; border-radius:8px; background:#FDF6E3; border:1px solid #E8D9A8;
          color:#7A5D00; font-size:13.5px; margin-bottom:16px; }
  .nota-legal{ margin-top:26px; padding:14px 16px; background:var(--soft); border-radius:8px;
               font-size:12.5px; line-height:1.6; color:var(--mut); }
  .nota-legal b{ color:var(--navy); }
</style>
</head>
<body>

<header class="cabecera">
  <div class="contenedor">
    <div class="marca">
      <div class="marca-sigla" aria-hidden="true">ISS</div>
      <div class="marca-nombre">
        <b>Equivalencias de monedas</b>
        <span>Diario Oficial de la Federación</span>
      </div>
    </div>
    <p class="cabecera-contacto">
      <a href="tipo-cambio.php">Tipo de cambio</a> · <a href="consulta.php">Listas del SAT</a> ·
      <a href="index.php">Administración</a> · <a href="../index.php">Portal</a>
    </p>
  </div>
</header>

<main class="seccion">
  <div class="contenedor">

    <?php if ($avisoCarga): ?>
      <div class="<?= str_starts_with($avisoCarga, 'Listo') ? 'estado' : 'aviso' ?>"
           style="margin-bottom:16px"><div><?= esc($avisoCarga) ?></div></div>
    <?php endif; ?>

    <?php if (!$estado['hay']): ?>
      <div class="cifra destacada">
        <h2>Las tablas mensuales todavía no están cargadas</h2>
        <p style="margin:6px 0 16px;font-size:14.5px;line-height:1.65;color:var(--mut)">
          Son <b>69 monedas por mes desde 2021</b>, cada una en su propia nota del
          DOF. Se traen de <?= EQ_POR_TANDA ?> en <?= EQ_POR_TANDA ?> para no
          agotar el tiempo del servidor: pulsa varias veces hasta que diga que no
          queda nada. Después se mantiene sola.
        </p>
        <form method="post" action="equivalencias.php">
          <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
          <input type="hidden" name="accion" value="traer">
          <button class="btn-buscar">Traer las tablas mensuales</button>
        </form>
      </div>
    <?php else: ?>

      <div class="estado">
        <div>
          <b><?= $estado['periodos'] ?> meses cargados</b>, de
          <?= esc(eq_mes_largo($estado['primero'])) ?> a
          <?= esc(eq_mes_largo($estado['ultimo'])) ?>.
          <div class="tenue">
            <?= number_format($estado['monedas']) ?> equivalencias en total.
            <?php if ($estado['faltan']): ?>
              <br><b style="color:#8C2733">Faltan meses:</b>
              <?= esc(implode(', ', $estado['faltan'])) ?>.
            <?php endif; ?>
          </div>
        </div>
        <form method="post" action="equivalencias.php" style="margin:0">
          <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
          <input type="hidden" name="accion" value="traer">
          <button class="btn-buscar" style="padding:8px 16px;font-size:13px">Buscar meses nuevos</button>
        </form>
      </div>

      <form class="buscador" method="get" action="equivalencias.php">
        <div class="campo">
          <label for="periodo">Mes</label>
          <select id="periodo" name="periodo">
            <?php foreach ($periodos as $p): ?>
              <option value="<?= esc($p['periodo']) ?>" <?= $periodo === $p['periodo'] ? 'selected' : '' ?>>
                <?= esc(eq_mes_largo($p['periodo'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo" style="flex:1 1 220px">
          <label for="q">País o moneda</label>
          <input type="text" id="q" name="q" value="<?= esc($busca) ?>" placeholder="Japón, Yen, Euro…">
        </div>
        <button class="btn-buscar">Consultar</button>
        <?php if ($busca !== ''): ?>
          <a class="limpiar" href="equivalencias.php?periodo=<?= esc($periodo) ?>">Ver las 69</a>
        <?php endif; ?>
      </form>

      <p class="seccion-nota">
        <?= count($filas) ?> moneda<?= count($filas) === 1 ? '' : 's' ?> en
        <b><?= esc(eq_mes_largo($periodo)) ?></b>.
      </p>

      <table class="datos">
        <tr><th>País</th><th>Moneda</th><th>Dólares por unidad</th>
            <th>Pesos por unidad<?= $tcHoy['hay'] ? '' : ' (falta el tipo de cambio)' ?></th></tr>
        <?php foreach ($filas as $f):
          /* La conversión a pesos se hace ya hecha: si la equivalencia viene por
             mil unidades, dividir es responsabilidad de esta pantalla, no de
             quien la lee con prisa. */
          $usd = (float)$f['equivalencia_usd'] / ($f['por_mil'] ? 1000 : 1);
          $mxn = $tcHoy['hay'] ? $usd * (float)$tcHoy['valor'] : null; ?>
          <tr class="<?= $f['por_mil'] ? 'pormil' : '' ?>">
            <td><?= esc($f['pais']) ?></td>
            <td><?= esc($f['moneda']) ?>
                <?php if ($f['por_mil']): ?><span class="etq">por mil</span><?php endif; ?></td>
            <td class="mono"><?= esc(rtrim(rtrim($f['equivalencia_usd'], '0'), '.')) ?>
                <?php if ($f['por_mil']): ?>
                  <div class="tenue">= <?= esc(rtrim(sprintf('%.8f', $usd), '0')) ?> por unidad</div>
                <?php endif; ?></td>
            <td class="mono"><?= $mxn !== null ? esc(rtrim(sprintf('%.6f', $mxn), '0')) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$filas): ?>
          <tr><td colspan="4" class="tenue">Sin coincidencias en ese mes.</td></tr>
        <?php endif; ?>
      </table>

      <p class="nota-legal">
        <b>Cómo se convierte.</b> Moneda extranjera → dólares con esta tabla del
        mes, y dólares → pesos con el tipo de cambio del DOF del día hábil
        anterior a la operación. La columna de pesos usa el último publicado
        (<?= $tcHoy['hay'] ? esc($tcHoy['ultima']) . ', ' . esc(rtrim(rtrim($tcHoy['valor'],'0'),'.')) : 'todavía sin cargar' ?>);
        para una fecha concreta, la de <a href="tipo-cambio.php">tipo de cambio</a>.
        <br><br>
        <b>Las marcadas «por mil».</b> El DOF expresa algunas equivalencias en
        dólares por mil unidades. Cuál lleva esa marca se lee del pie de cada
        publicación, no del número de la llamada: en 2021 era «2/» y en 2026 es
        «3/», porque al añadirse el yuan extracontinental las llamadas rotaron.
        Aquí ya viene dividido.
      </p>

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
