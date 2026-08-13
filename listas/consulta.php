<?php
/* ============================================================================
   consulta.php — buscar un RFC y explorar los listados.

   Dos usos en una pantalla:
   · Verificar un proveedor o cliente: se teclea su RFC y sale su situación y
     su historial. Esto es lo que hay que hacer antes de deducir una factura.
   · Buscar prospectos: el listado filtrable de presuntos, que son los que
     tienen 15 días hábiles para desvirtuar y necesitan despacho ya.

   Toda consulta queda en la bitácora. Los listados traen personas físicas: son
   datos personales y el registro de acceso es exigible.
   ============================================================================ */

require __DIR__ . '/../acceso.php';
acceso_exigir();
require_once __DIR__ . '/cron/lib/bd.php';
require_once __DIR__ . '/cron/lib/csv_sat.php';

$rfcBuscado = trim((string)($_GET['rfc'] ?? ''));
$fSituacion = (string)($_GET['situacion'] ?? '');
$fTipo      = (string)($_GET['tipo'] ?? '');
$fTexto     = trim((string)($_GET['q'] ?? ''));
$pagina     = max(1, (int)($_GET['p'] ?? 1));
const POR_PAGINA = 50;

$listo = false; $errorBD = '';
try { bd()->query("SELECT 1 FROM estatus LIMIT 1"); $listo = true; }
catch (Throwable $e) { $errorBD = $e->getMessage(); }

function bitacora(string $origen, ?string $rfc, int $cantidad, string $resultado): void
{
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        bd()->prepare("INSERT INTO bitacora (usuario,ip,origen,rfc_consultado,cantidad,resultado,consultado_en)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([ACCESO_USUARIO, $ip ? @inet_pton($ip) : null, $origen,
                       $rfc, $cantidad, $resultado, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { /* la bitácora no debe tumbar la consulta */ }
}

/* ------------------------------------------------------------ búsqueda RFC */
$resultado = null; $historial = []; $rfcNorm = null; $rfcAviso = '';
if ($listo && $rfcBuscado !== '') {
    $n = csv_sat_rfc($rfcBuscado);
    $rfcNorm = $n['rfc'];
    if (!$n['valido']) {
        $rfcAviso = match ($n['motivo']) {
            'vacio'    => 'Escriba un RFC.',
            'formato'  => 'Ese RFC no tiene forma válida. Revise que esté completo.',
            default    => 'Ese RFC no tiene la longitud correcta: son 12 caracteres para persona moral y 13 para física.',
        };
    } else {
        $st = bd()->prepare("
            SELECT lista, situacion, supuesto, nombre, tipo_persona, entidad, proc_texto,
                   valido_desde, valido_hasta, vigente
            FROM estatus WHERE rfc = ?
            ORDER BY vigente IS NULL, lista, valido_desde DESC");
        $st->execute([$rfcNorm]);
        $historial = $st->fetchAll();
        $resultado = array_values(array_filter($historial, fn($r) => $r['vigente'] !== null));
        bitacora('web', $rfcNorm, 1, $resultado ? 'aparece' : 'no aparece');
    }
}

/* --------------------------------------------------------------- listado */
$filas = []; $total = 0; $situaciones = [];
if ($listo && $rfcBuscado === '') {
    // Solo el listado completo: los otros archivos del 69-B son subconjuntos
    // suyos, y contarlos todos duplicaría a cada contribuyente.
    $situaciones = bd()->query("
        SELECT situacion, COUNT(*) n FROM estatus
        WHERE vigente = 1 AND lista = 'art69b.completo'
          AND situacion IS NOT NULL AND situacion <> ''
        GROUP BY situacion ORDER BY n DESC")->fetchAll();

    $donde = ["vigente = 1"]; $par = [];
    if ($fSituacion !== '') { $donde[] = "situacion = ?";    $par[] = $fSituacion; }
    if ($fTipo !== '')      { $donde[] = "tipo_persona = ?"; $par[] = $fTipo; }
    if ($fTexto !== '')     { $donde[] = "(nombre LIKE ? OR rfc LIKE ?)";
                              $par[] = "%$fTexto%"; $par[] = strtoupper($fTexto) . "%"; }
    // El listado completo ya contiene a los demás: se consulta solo ese para
    // no mostrar el mismo contribuyente cinco veces.
    $donde[] = "lista = 'art69b.completo'";
    $w = implode(' AND ', $donde);

    $c = bd()->prepare("SELECT COUNT(*) FROM estatus WHERE $w");
    $c->execute($par);
    $total = (int)$c->fetchColumn();

    $off = ($pagina - 1) * POR_PAGINA;
    $q = bd()->prepare("SELECT rfc,nombre,situacion,tipo_persona,proc_texto,valido_desde
                        FROM estatus WHERE $w ORDER BY nombre LIMIT " . POR_PAGINA . " OFFSET $off");
    $q->execute($par);
    $filas = $q->fetchAll();
    if ($fSituacion || $fTipo || $fTexto) bitacora('web', null, count($filas), 'listado');
}

$paginas = (int)ceil($total / POR_PAGINA);
function url(array $cambios = []): string
{
    $p = array_merge($_GET, $cambios);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
}
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consulta de listas del SAT — International Support Services, S.C.</title>
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
  .campo input:focus, .campo select:focus{ outline:none; border-color:var(--acc);
     box-shadow:0 0 0 3px rgba(29,111,165,.16); }
  .btn-buscar{ font:inherit; font-size:14.5px; font-weight:600; color:#fff;
     background:var(--acc); border:0; border-radius:7px; padding:10px 20px; cursor:pointer; }
  .btn-buscar:hover{ background:#17608F; }
  .limpiar{ font-size:13px; color:var(--mut); text-decoration:none; padding:10px 4px; }
  .limpiar:hover{ color:var(--acc); text-decoration:underline; }

  .ficha{ padding:22px; background:#fff; border:1px solid var(--rule);
          border-radius:10px; margin-bottom:16px; }
  .ficha.hallado{ border-left:4px solid var(--bad); }
  .ficha.limpio{ border-left:4px solid #2E8B57; }
  .ficha h2{ margin:0 0 4px; font-size:19px; color:var(--navy); }
  .ficha .rfc{ font-family:ui-monospace,Consolas,monospace; font-size:15px; color:var(--mut); }
  .veredicto{ margin:14px 0 0; font-size:14.5px; line-height:1.6; }
  .veredicto b{ color:var(--navy); }

  .etq{ display:inline-block; padding:3px 10px; border-radius:999px; font-size:11.5px;
        font-weight:700; letter-spacing:.03em; }
  .etq-Presunto{ background:#FDF0D5; color:#8A5B00; }
  .etq-Definitivo{ background:#FBEEF0; color:#8C2733; }
  .etq-Desvirtuado{ background:#EAF3FB; color:#1D6FA5; }
  .etq-SentenciaFavorable{ background:#EDF7F0; color:#2E7D4F; }

  table.datos{ width:100%; border-collapse:collapse; font-size:13.5px; background:#fff;
               border:1px solid var(--rule); border-radius:10px; overflow:hidden; }
  table.datos th{ text-align:left; font-size:11px; letter-spacing:.08em; text-transform:uppercase;
                  color:var(--mut); padding:11px 12px; background:#F7F9FB;
                  border-bottom:1px solid var(--rule); }
  table.datos td{ padding:11px 12px; border-bottom:1px solid var(--rule); vertical-align:top; }
  table.datos tr:last-child td{ border-bottom:0; }
  .mono{ font-family:ui-monospace,Consolas,monospace; font-size:12.5px; }
  .tenue{ color:var(--mut); font-size:12.5px; }
  .paginacion{ display:flex; gap:8px; align-items:center; margin-top:16px; font-size:13.5px; }
  .paginacion a{ padding:7px 13px; border:1px solid var(--rule); border-radius:6px;
                 text-decoration:none; color:var(--acc); background:#fff; }
  .paginacion a:hover{ border-color:var(--acc); }
  .alerta{ padding:12px 14px; border-radius:8px; background:#FBEEF0; border:1px solid #E6B9BF;
           color:#8C2733; font-size:13.5px; margin-bottom:16px; }
  .nota-legal{ margin-top:26px; padding:14px 16px; background:var(--soft); border-radius:8px;
               font-size:12.5px; line-height:1.6; color:var(--mut); }
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
    <p class="cabecera-contacto">
      <a href="lote.php">Consulta por lote</a> · <a href="alertas.php">Alertas</a> ·
      <a href="index.php">Administración</a> · <a href="../index.php">Portal</a>
    </p>
  </div>
</header>

<main class="seccion">
  <div class="contenedor">

    <?php if (!$listo): ?>
      <div class="alerta"><b>Todavía no hay datos cargados.</b><br>
        Ve a <a href="index.php">Administración</a> y completa la puesta en marcha.</div>
    <?php else: ?>

    <form class="buscador" method="get" action="consulta.php">
      <div class="campo" style="flex:1 1 260px">
        <label for="rfc">Buscar un RFC</label>
        <input type="text" id="rfc" name="rfc" value="<?= esc($rfcBuscado) ?>"
               placeholder="AAA080808HL8" autocapitalize="characters" autocomplete="off">
      </div>
      <button class="btn-buscar">Consultar</button>
      <?php if ($rfcBuscado !== ''): ?>
        <a class="limpiar" href="consulta.php">Ver el listado completo</a>
      <?php endif; ?>
    </form>

    <?php if ($rfcAviso): ?>
      <div class="alerta"><?= esc($rfcAviso) ?></div>
    <?php endif; ?>

    <?php /* ---------------- resultado de un RFC ---------------- */ ?>
    <?php if ($rfcNorm && !$rfcAviso): ?>
      <?php $hay = !empty($resultado); ?>
      <div class="ficha <?= $hay ? 'hallado' : 'limpio' ?>">
        <h2><?= $hay ? esc($resultado[0]['nombre']) : 'Sin coincidencias' ?></h2>
        <div class="rfc"><?= esc($rfcNorm) ?></div>

        <p class="veredicto">
          <?php if ($hay): ?>
            <b>Aparece en los listados publicados por el SAT.</b>
            <?= count($resultado) ?> expediente<?= count($resultado) > 1 ? 's' : '' ?> vigente<?= count($resultado) > 1 ? 's' : '' ?>.
          <?php else: ?>
            <b>No aparece en la versión de los archivos que tenemos cargada.</b>
            Esto no certifica que el contribuyente esté libre de cualquier
            supuesto: solo dice que no figura en los archivos consultados, con la
            fecha que se indica abajo.
          <?php endif; ?>
        </p>
      </div>

      <?php if ($historial): ?>
        <table class="datos">
          <tr><th>Lista</th><th>Situación</th><th>Oficio de presunción</th>
              <th>Vigencia</th></tr>
          <?php foreach ($historial as $h):
            $cls = 'etq-' . str_replace(' ', '', (string)$h['situacion']); ?>
            <tr<?= $h['vigente'] === null ? ' style="opacity:.55"' : '' ?>>
              <td class="mono"><?= esc($h['lista']) ?></td>
              <td><?php if ($h['situacion']): ?>
                    <span class="etq <?= esc($cls) ?>"><?= esc($h['situacion']) ?></span>
                  <?php else: ?><?= esc($h['supuesto'] ?? '—') ?><?php endif; ?></td>
              <td class="tenue"><?= esc($h['proc_texto'] ?? '—') ?></td>
              <td class="tenue">
                desde <?= esc($h['valido_desde']) ?>
                <?= $h['vigente'] === null ? ' · hasta ' . esc($h['valido_hasta']) : ' · vigente' ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
        <?php if (count($historial) > count($resultado)): ?>
          <p class="tenue" style="margin-top:10px">
            Las filas atenuadas son historial: expedientes que ya no están vigentes.
            Un mismo RFC puede pasar por el procedimiento más de una vez.
          </p>
        <?php endif; ?>
      <?php endif; ?>

    <?php else: ?>
      <?php /* ---------------- listado filtrable ---------------- */ ?>
      <form class="buscador" method="get" action="consulta.php">
        <div class="campo">
          <label for="situacion">Situación</label>
          <select id="situacion" name="situacion">
            <option value="">Todas</option>
            <?php foreach ($situaciones as $s): ?>
              <option value="<?= esc($s['situacion']) ?>" <?= $fSituacion === $s['situacion'] ? 'selected' : '' ?>>
                <?= esc($s['situacion']) ?> (<?= number_format((int)$s['n']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label for="tipo">Tipo</label>
          <select id="tipo" name="tipo">
            <option value="">Todos</option>
            <option value="M" <?= $fTipo === 'M' ? 'selected' : '' ?>>Persona moral</option>
            <option value="F" <?= $fTipo === 'F' ? 'selected' : '' ?>>Persona física</option>
          </select>
        </div>
        <div class="campo" style="flex:1 1 220px">
          <label for="q">Nombre o RFC</label>
          <input type="text" id="q" name="q" value="<?= esc($fTexto) ?>" placeholder="parte del nombre">
        </div>
        <button class="btn-buscar">Filtrar</button>
        <?php if ($fSituacion || $fTipo || $fTexto): ?>
          <a class="limpiar" href="consulta.php">Quitar filtros</a>
        <?php endif; ?>
      </form>

      <p class="seccion-nota"><?= number_format($total) ?> contribuyentes
        <?= $fSituacion || $fTipo || $fTexto ? 'con esos filtros' : 'en el listado completo del 69-B' ?>.</p>

      <table class="datos">
        <tr><th>RFC</th><th>Nombre</th><th>Situación</th><th>Tipo</th><th>Desde</th></tr>
        <?php foreach ($filas as $f):
          $cls = 'etq-' . str_replace(' ', '', (string)$f['situacion']); ?>
          <tr>
            <td class="mono"><a href="<?= esc(url(['rfc' => $f['rfc'], 'p' => null])) ?>"><?= esc($f['rfc']) ?></a></td>
            <td><?= esc($f['nombre']) ?></td>
            <td><span class="etq <?= esc($cls) ?>"><?= esc($f['situacion']) ?></span></td>
            <td class="tenue"><?= $f['tipo_persona'] === 'M' ? 'Moral' : ($f['tipo_persona'] === 'F' ? 'Física' : '—') ?></td>
            <td class="tenue"><?= esc($f['valido_desde']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$filas): ?>
          <tr><td colspan="5" class="tenue">Ningún contribuyente coincide con esos filtros.</td></tr>
        <?php endif; ?>
      </table>

      <?php if ($paginas > 1): ?>
        <div class="paginacion">
          <?php if ($pagina > 1): ?><a href="<?= esc(url(['p' => $pagina - 1])) ?>">← Anterior</a><?php endif; ?>
          <span class="tenue">Página <?= $pagina ?> de <?= number_format($paginas) ?></span>
          <?php if ($pagina < $paginas): ?><a href="<?= esc(url(['p' => $pagina + 1])) ?>">Siguiente →</a><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <p class="nota-legal">
      Los datos provienen de los listados que publica el SAT. Un resultado
      negativo significa <b>que el RFC no figura en la versión de los archivos
      cargada</b>, no que el contribuyente esté libre de cualquier supuesto.
      Los listados incluyen personas físicas: cada consulta queda registrada en
      la bitácora del sistema.
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
