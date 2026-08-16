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
require_once __DIR__ . '/cabecera.php';
require_once __DIR__ . '/cron/lib/migracion.php';

// Bases creadas con una versión anterior: se ponen al día solas. Las demás
// pantallas ya lo hacían; estas dos no, y bastaba con entrar por aquí
// primero para toparse con una columna que todavía no existía.
try { migrar_columnas_pendientes(); } catch (Throwable $e) { /* se dirá abajo */ }
require_once __DIR__ . '/cron/lib/bd.php';
require_once __DIR__ . '/cron/lib/csv_sat.php';
require_once __DIR__ . '/cron/lib/cobertura.php';

$rfcBuscado = trim((string)($_GET['rfc'] ?? ''));
$fSituacion = (string)($_GET['situacion'] ?? '');
$fTipo      = (string)($_GET['tipo'] ?? '');
$fTexto     = trim((string)($_GET['q'] ?? ''));
$fAnio      = preg_match('/^\d{4}$/', (string)($_GET['anio'] ?? '')) ? (string)$_GET['anio'] : '';
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
$filas = []; $total = 0; $situaciones = []; $anios = [];
if ($listo && $rfcBuscado === '') {
    /* Años del oficio, para el filtro.
       La fecha que sirve para filtrar NO es valido_desde —la columna «Desde»—:
       esa dice desde cuándo consta así en esta herramienta, y medido el
       13/08/2026 vale 2026-05-31 en los 14 432 registros, porque todos entraron
       en la misma carga inicial. Filtrar por ahí no separaría nada.
       La que sí separa es la del oficio global, que va de 2014 a 2026. Está al
       final del texto del oficio y se saca con los cuatro últimos caracteres:
       comprobado, los 14 432 la traen y ninguno se queda fuera. No se intenta
       interpretar el número de oficio, que sí tiene tres formatos distintos
       conviviendo (ver docs/ESQUEMAS.md §6). */
    $anios = bd()->query("
        SELECT SUBSTRING(TRIM(proc_texto), -4) anio, COUNT(*) n
        FROM estatus
        WHERE vigente = 1 AND lista = 'art69b.completo'
          AND TRIM(proc_texto) REGEXP '[0-9]{4}$'
        GROUP BY anio ORDER BY anio DESC")->fetchAll();

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
    if ($fAnio !== '')      { $donde[] = "SUBSTRING(TRIM(proc_texto), -4) = ?"; $par[] = $fAnio; }
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
    if ($fSituacion || $fTipo || $fTexto || $fAnio) bitacora('web', null, count($filas), 'listado');
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
<title>Consultar RFC · International Support Services</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="../css/portal.css">
<style>
  .ficha{ padding:22px; background:#fff; border:1px solid var(--rule);
          border-radius:10px; margin-bottom:16px; }
  .ficha.hallado{ border-left:4px solid var(--bad); }
  .ficha.limpio{ border-left:4px solid #2E8B57; }
  .ficha h2{ margin:0 0 4px; font-size:19px; color:var(--navy); }
  .ficha .rfc{ font-family:ui-monospace,Consolas,monospace; font-size:15px; color:var(--mut); }
  .veredicto{ margin:14px 0 0; font-size:14.5px; line-height:1.6; }
  .veredicto b{ color:var(--navy); }
</style>
</head>
<body>

<?php cabecera('consulta', 'Listas del SAT', 'Artículo 69 · 69-B · 69-B Bis'); ?>

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
          <?php else: $cob = cobertura(); $cub = cobertura_articulos_cubiertos(); ?>
            <?php if ($cob['completa']): ?>
              <b>No aparece en la versión de los archivos que tenemos cargada.</b>
            <?php else: ?>
              <b>No aparece en <?= $cub ? esc(cobertura_articulos_texto($cub)) : 'ninguna lista cargada' ?>.</b>
              <?= esc(cobertura_articulos_texto($cob['articulos_incompletos'])) ?>
              todavía no está<?= count($cob['articulos_incompletos']) > 1 ? 'n' : '' ?>
              cargado<?= count($cob['articulos_incompletos']) > 1 ? 's' : '' ?>,
              así que sobre eso esta consulta no dice nada.
            <?php endif; ?>
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
        <div class="campo">
          <label for="anio">Año del oficio</label>
          <select id="anio" name="anio">
            <option value="">Todos</option>
            <?php foreach ($anios as $a): ?>
              <option value="<?= esc($a['anio']) ?>" <?= $fAnio === $a['anio'] ? 'selected' : '' ?>>
                <?= esc($a['anio']) ?> (<?= number_format((int)$a['n']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo" style="flex:1 1 220px">
          <label for="q">Nombre o RFC</label>
          <input type="text" id="q" name="q" value="<?= esc($fTexto) ?>" placeholder="parte del nombre">
        </div>
        <button class="btn-buscar">Filtrar</button>
        <?php if ($fSituacion || $fTipo || $fTexto || $fAnio): ?>
          <a class="limpiar" href="consulta.php">Quitar filtros</a>
        <?php endif; ?>
      </form>

      <p class="seccion-nota"><?= number_format($total) ?> contribuyentes
        <?= $fSituacion || $fTipo || $fTexto || $fAnio ? 'con esos filtros' : 'en el listado completo del 69-B' ?>.</p>

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

<?php pie(); ?>

</body>
</html>
