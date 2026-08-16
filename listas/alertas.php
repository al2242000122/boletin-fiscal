<?php
/* ============================================================================
   alertas.php — movimientos detectados en los listados del SAT.

   Es el producto: el listado completo ya lo venden varios. Lo que vale es
   enterarse el mismo día de que un RFC entró como presunto, porque desde la
   publicación corren 15 días hábiles para desvirtuar.

   No se muestran los eventos de la carga inicial: esa es la línea base, no
   movimiento. Se filtran por snapshots.linea_base = 0.
   ============================================================================ */

require __DIR__ . '/../acceso.php';
acceso_exigir();
require_once __DIR__ . '/cron/lib/migracion.php';

$fTipo  = (string)($_GET['tipo'] ?? '');
$fPrio  = (string)($_GET['prioridad'] ?? '');
$fDesde = (string)($_GET['desde'] ?? '');
$pagina = max(1, (int)($_GET['p'] ?? 1));
const POR_PAGINA = 60;

$listo = false; $errorBD = '';
try {
    bd()->query("SELECT 1 FROM eventos LIMIT 1");
    migrar_columnas_pendientes();   // la columna linea_base puede no existir aún
    $listo = true;
} catch (Throwable $e) { $errorBD = $e->getMessage(); }

$eventos = []; $total = 0; $resumen = []; $hayLineaBase = false; $ultimoMovimiento = null;

if ($listo) {
    $hayLineaBase = (bool)bd()->query("SELECT COUNT(*) FROM snapshots WHERE linea_base = 1")->fetchColumn();

    $donde = ["s.linea_base = 0"]; $par = [];
    if ($fTipo !== '')  { $donde[] = "e.tipo = ?";      $par[] = $fTipo; }
    if ($fPrio !== '')  { $donde[] = "e.prioridad >= ?"; $par[] = (int)$fPrio; }
    if ($fDesde !== '') { $donde[] = "e.detectado_en >= ?"; $par[] = $fDesde . ' 00:00:00'; }
    $w = implode(' AND ', $donde);

    $c = bd()->prepare("SELECT COUNT(*) FROM eventos e JOIN snapshots s ON s.id = e.snapshot_id WHERE $w");
    $c->execute($par);
    $total = (int)$c->fetchColumn();

    $off = ($pagina - 1) * POR_PAGINA;
    $q = bd()->prepare("
        SELECT e.*, s.fecha_archivo
        FROM eventos e JOIN snapshots s ON s.id = e.snapshot_id
        WHERE $w
        ORDER BY e.prioridad DESC, e.detectado_en DESC, e.id DESC
        LIMIT " . POR_PAGINA . " OFFSET $off");
    $q->execute($par);
    $eventos = $q->fetchAll();

    $resumen = bd()->query("
        SELECT e.tipo, COUNT(*) n FROM eventos e JOIN snapshots s ON s.id = e.snapshot_id
        WHERE s.linea_base = 0 GROUP BY e.tipo")->fetchAll();

    $ultimoMovimiento = bd()->query("
        SELECT MAX(e.detectado_en) FROM eventos e JOIN snapshots s ON s.id = e.snapshot_id
        WHERE s.linea_base = 0")->fetchColumn();
}

$paginas = (int)ceil($total / POR_PAGINA);
function url(array $c = []): string {
    return '?' . http_build_query(array_filter(array_merge($_GET, $c), fn($v) => $v !== '' && $v !== null));
}
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Alertas · Listas del SAT</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="../css/portal.css">
<style>
  .filtros{ display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; padding:18px 20px;
            background:#fff; border:1px solid var(--rule); border-radius:10px; margin-bottom:18px; }
  .campo{ display:flex; flex-direction:column; gap:5px; }
  .campo label{ font-size:11px; font-weight:700; letter-spacing:.08em;
                text-transform:uppercase; color:var(--mut); }
  .campo select, .campo input{ font:inherit; font-size:14px; padding:8px 11px;
     border:1px solid var(--rule); border-radius:7px; background:#fff; color:var(--ink); }
  .btn-buscar{ font:inherit; font-size:14px; font-weight:600; color:#fff; background:var(--acc);
     border:0; border-radius:7px; padding:9px 18px; cursor:pointer; }
  .limpiar{ font-size:13px; color:var(--mut); text-decoration:none; padding:9px 4px; }

  .vacio{ padding:34px 26px; background:#fff; border:1px solid var(--rule);
          border-radius:10px; text-align:center; }
  .vacio h2{ margin:0 0 8px; font-size:17px; color:var(--navy); }
  .vacio p{ margin:0 auto; max-width:62ch; font-size:13.8px; line-height:1.65; color:var(--mut); }

  table.datos{ width:100%; border-collapse:collapse; font-size:13.5px; background:#fff;
               border:1px solid var(--rule); border-radius:10px; overflow:hidden; }
  table.datos th{ text-align:left; font-size:11px; letter-spacing:.08em; text-transform:uppercase;
                  color:var(--mut); padding:11px 12px; background:#F7F9FB;
                  border-bottom:1px solid var(--rule); }
  table.datos td{ padding:11px 12px; border-bottom:1px solid var(--rule); vertical-align:top; }
  table.datos tr:last-child td{ border-bottom:0; }
  tr.urgente{ background:#FFF9F0; }
  .mono{ font-family:ui-monospace,Consolas,monospace; font-size:12.5px; }
  .tenue{ color:var(--mut); font-size:12.5px; }
  .etq{ display:inline-block; padding:3px 10px; border-radius:999px; font-size:11.5px; font-weight:700; }
  .etq-alta{ background:#FDF0D5; color:#8A5B00; }
  .etq-cambio{ background:#EAF3FB; color:#1D6FA5; }
  .etq-baja{ background:#EDF7F0; color:#2E7D4F; }
  .flecha{ color:var(--mut); }
  .resumen{ display:flex; gap:26px; flex-wrap:wrap; padding:18px 22px; background:var(--soft);
            border-radius:10px; margin-bottom:18px; }
  .resumen div b{ display:block; font-size:22px; color:var(--navy); }
  .resumen div span{ font-size:12px; letter-spacing:.06em; text-transform:uppercase; color:var(--mut); }
  .paginacion{ display:flex; gap:8px; align-items:center; margin-top:16px; font-size:13.5px; }
  .paginacion a{ padding:7px 13px; border:1px solid var(--rule); border-radius:6px;
                 text-decoration:none; color:var(--acc); background:#fff; }
  .alerta{ padding:12px 14px; border-radius:8px; background:#FBEEF0; border:1px solid #E6B9BF;
           color:#8C2733; font-size:13.5px; margin-bottom:16px; }
</style>
</head>
<body>

<header class="cabecera">
  <div class="contenedor">
    <div class="marca">
      <div class="marca-sigla" aria-hidden="true">ISS</div>
      <div class="marca-nombre"><b>Alertas</b><span>Movimientos en los listados</span></div>
    </div>
    <p class="cabecera-contacto">
      <a href="consulta.php">Consultar RFC</a> · <a href="lote.php">Por lote</a> ·
      <a href="tipo-cambio.php">Tipo de cambio</a> · <a href="equivalencias.php">Equivalencias</a> ·
      <a href="index.php">Administración</a> · <a href="../index.php">Portal</a>
    </p>
  </div>
</header>

<main class="seccion">
  <div class="contenedor">

    <?php if (!$listo): ?>
      <div class="alerta"><b>Todavía no hay datos.</b>
        Ve a <a href="index.php">Administración</a> y completa la puesta en marcha.
        <?php if ($errorBD): ?><br><span class="tenue"><?= esc($errorBD) ?></span><?php endif; ?></div>
    <?php else: ?>

      <?php if ($resumen): ?>
        <div class="resumen">
          <?php $porTipo = []; foreach ($resumen as $r) $porTipo[$r['tipo']] = (int)$r['n']; ?>
          <div><b><?= number_format($porTipo['alta'] ?? 0) ?></b><span>Altas</span></div>
          <div><b><?= number_format($porTipo['cambio'] ?? 0) ?></b><span>Cambios</span></div>
          <div><b><?= number_format($porTipo['baja'] ?? 0) ?></b><span>Bajas</span></div>
          <?php if ($ultimoMovimiento): ?>
            <div><b style="font-size:15px"><?= esc(substr((string)$ultimoMovimiento, 0, 16)) ?></b>
                 <span>Último movimiento</span></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form class="filtros" method="get" action="alertas.php">
        <div class="campo">
          <label for="tipo">Tipo</label>
          <select id="tipo" name="tipo">
            <option value="">Todos</option>
            <option value="alta"   <?= $fTipo==='alta'?'selected':'' ?>>Altas</option>
            <option value="cambio" <?= $fTipo==='cambio'?'selected':'' ?>>Cambios de situación</option>
            <option value="baja"   <?= $fTipo==='baja'?'selected':'' ?>>Bajas</option>
          </select>
        </div>
        <div class="campo">
          <label for="prioridad">Importancia</label>
          <select id="prioridad" name="prioridad">
            <option value="">Todas</option>
            <option value="2" <?= $fPrio==='2'?'selected':'' ?>>Solo urgentes</option>
            <option value="1" <?= $fPrio==='1'?'selected':'' ?>>Urgentes y relevantes</option>
          </select>
        </div>
        <div class="campo">
          <label for="desde">Desde</label>
          <input type="date" id="desde" name="desde" value="<?= esc($fDesde) ?>">
        </div>
        <button class="btn-buscar">Filtrar</button>
        <?php if ($fTipo || $fPrio || $fDesde): ?>
          <a class="limpiar" href="alertas.php">Quitar filtros</a>
        <?php endif; ?>
      </form>

      <?php if (!$eventos): ?>
        <div class="vacio">
          <h2>Sin movimientos<?= $fTipo || $fPrio || $fDesde ? ' con esos filtros' : '' ?></h2>
          <p>
            <?php if ($hayLineaBase && !$total): ?>
              La carga inicial quedó registrada como línea base, no como novedad:
              no tendría sentido avisar de 14 mil contribuyentes que ya estaban
              publicados. A partir de aquí, cada vez que el SAT publique una
              versión nueva aparecerán aquí las diferencias.
              <br><br>
              El 69-B se publica cada uno o dos meses, así que es normal que esto
              esté vacío un buen rato. La tarea programada lo revisa todos los días.
            <?php else: ?>
              No hay eventos que coincidan.
            <?php endif; ?>
          </p>
        </div>
      <?php else: ?>
        <p class="seccion-nota"><?= number_format($total) ?> movimiento<?= $total===1?'':'s' ?>.</p>
        <table class="datos">
          <tr><th>RFC</th><th>Nombre</th><th>Movimiento</th><th>Persona</th>
              <th>Publicación</th><th>Detectado</th></tr>
          <?php foreach ($eventos as $e): ?>
            <tr class="<?= (int)$e['prioridad'] === 2 ? 'urgente' : '' ?>">
              <td class="mono"><a href="consulta.php?rfc=<?= esc($e['rfc']) ?>"><?= esc($e['rfc']) ?></a></td>
              <td><?= esc(mb_substr((string)$e['nombre'], 0, 70)) ?></td>
              <td>
                <span class="etq etq-<?= esc($e['tipo']) ?>"><?= esc(ucfirst($e['tipo'])) ?></span>
                <?php if ($e['tipo'] === 'cambio'): ?>
                  <div class="tenue"><?= esc($e['situacion_anterior'] ?? '—') ?>
                    <span class="flecha">→</span> <?= esc($e['situacion_nueva'] ?? '—') ?></div>
                <?php elseif ($e['tipo'] === 'baja'): ?>
                  <!-- En una baja lo que informa es de dónde salió, no a dónde: el
                       archivo nuevo simplemente ya no lo trae. -->
                  <div class="tenue">Estaba como <?= esc($e['situacion_anterior'] ?? '—') ?>;
                    ya no aparece en el archivo</div>
                <?php elseif ($e['situacion_nueva']): ?>
                  <div class="tenue"><?= esc($e['situacion_nueva']) ?></div>
                <?php endif; ?>
              </td>
              <td class="tenue"><?= $e['tipo_persona']==='M'?'Moral':($e['tipo_persona']==='F'?'Física':'—') ?></td>
              <td class="tenue"><?= esc($e['fecha_archivo'] ?? '—') ?></td>
              <td class="tenue"><?= esc(substr((string)$e['detectado_en'], 0, 16)) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>

        <?php if ($paginas > 1): ?>
          <div class="paginacion">
            <?php if ($pagina > 1): ?><a href="<?= esc(url(['p'=>$pagina-1])) ?>">← Anterior</a><?php endif; ?>
            <span class="tenue">Página <?= $pagina ?> de <?= number_format($paginas) ?></span>
            <?php if ($pagina < $paginas): ?><a href="<?= esc(url(['p'=>$pagina+1])) ?>">Siguiente →</a><?php endif; ?>
          </div>
        <?php endif; ?>
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
