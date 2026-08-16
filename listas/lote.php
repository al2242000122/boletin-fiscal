<?php
/* ============================================================================
   lote.php — consultar muchos RFC de una vez.

   Un contador no verifica un proveedor: verifica los trescientos que tiene en
   el papel de trabajo. Se pega la lista —o se sube el archivo tal cual, con
   nombres y saldos— y sale la situación vigente de cada uno, descargable.

   Tres decisiones que no son obvias y que conviene no deshacer:

   · Desvirtuado y Sentencia Favorable NO son hallazgo adverso. Aparecen en el
     listado, sí, pero el procedimiento se resolvió a favor del contribuyente.
     Pintarlos en rojo junto a los definitivos le cuesta un proveedor bueno al
     cliente, y es el error más caro que puede cometer esta pantalla.

   · Los cuatro archivos sueltos del 69-B (Presuntos, Definitivos…) son
     subconjuntos del listado completo: el mismo expediente sale dos veces. Se
     muestran las dos apariciones —son fuentes distintas y eso es lo que se
     acredita— pero agrupadas como un solo expediente, y el veredicto se calcula
     una sola vez. Sin esto, un lote de 300 proveedores se lee como el doble de
     hallazgos de los que hay.

   · La fecha que se cita como «datos al …» es la del archivo vigente de cada
     lista, no la del snapshot en que se abrió la fila. Son cosas distintas:
     `valido_desde` dice desde cuándo consta así, y puede ser de hace tres
     versiones. Ver el mapa de listas más abajo.
   ============================================================================ */

require __DIR__ . '/../acceso.php';
acceso_exigir();
require_once __DIR__ . '/cron/lib/bd.php';
require_once __DIR__ . '/cron/lib/csv_sat.php';
require_once __DIR__ . '/cron/lib/fuentes.php';   // solo por FUENTES_CATALOGO
require_once __DIR__ . '/cron/lib/cobertura.php';

const LOTE_TOPE       = 5000;               // RFC por lote
const LOTE_TROZO      = 500;                // marcadores por sentencia
const LOTE_MAX_BYTES  = 2 * 1024 * 1024;    // tamaño del archivo subido
const LOTE_MUESTRA    = 200;                // filas que se miran para detectar la columna de RFC
// Tope de filas pintadas. Medido: un lote de 5 000 RFC con 3 998 hallazgos
// genera 4 MB de HTML y el navegador se arrastra. El CSV los lleva todos.
const LOTE_EN_PANTALLA = 400;

/* ==========================================================================
   Veredicto

   La prioridad es la que le importa al contador, no la del SAT: primero lo que
   anula efectos fiscales, después lo que corre plazo, y al final lo que aparece
   sin consecuencia adversa.
   ========================================================================== */
function lote_clasificar(array $f): array
{
    $sit   = trim((string)$f['situacion']);
    $lista = (string)$f['lista'];

    /* Va lo primero, antes de mirar en qué lista está: una sentencia favorable
       en el 69-B Bis es tan favorable como en el 69-B. La regla es del hecho,
       no del archivo donde se leyó. */
    if ($sit === 'Desvirtuado' || $sit === 'Sentencia Favorable') {
        return ['gravedad' => 5, 'clave' => 'sin_efecto_adverso',
                'etiqueta' => $sit === 'Desvirtuado' ? 'Desvirtuado' : 'Sentencia favorable',
                'resumen'  => 'Aparece en el listado, pero el procedimiento se resolvió a su favor.'];
    }

    if (str_starts_with($lista, 'art69b.')) {
        if ($sit === 'Definitivo') {
            return ['gravedad' => 1, 'clave' => 'definitivo_69b', 'etiqueta' => 'Definitivo 69-B',
                    'resumen'  => 'Listado definitivo del artículo 69-B.'];
        }
        if ($sit === 'Presunto') {
            return ['gravedad' => 2, 'clave' => 'presunto_69b', 'etiqueta' => 'Presunto 69-B',
                    'resumen'  => 'Presunto en el artículo 69-B. Corre el plazo para desvirtuar.'];
        }
        // ESQUEMAS.md: los valores medidos son exactamente cuatro y no hay
        // filas con la situación vacía. Si aparece otro, se dice, no se supone.
        return ['gravedad' => 4, 'clave' => 'otro_69b', 'etiqueta' => $sit !== '' ? $sit : 'Sin situación',
                'resumen'  => 'Aparece en el 69-B con una situación no prevista por esta herramienta.'];
    }

    if (str_starts_with($lista, 'bis.')) {
        return ['gravedad' => 3, 'clave' => 'bis', 'etiqueta' => '69-B Bis',
                'resumen'  => 'Artículo 69-B Bis, transmisión indebida de pérdidas fiscales.'];
    }

    // Artículo 69: no se interpreta el supuesto, se muestra tal cual viene.
    // Son siete listas distintas (Firmes, No localizados, Cancelados, CSD sin
    // efectos, Exigibles, Entes públicos, Sentencias) y no todas significan lo
    // mismo. Quien lee la tabla decide.
    // El supuesto viene en mayúsculas en el archivo ("NO LOCALIZADOS"). Se baja
    // a minúsculas y se sube solo la inicial: en español, "No localizados", no
    // "No Localizados", que es como lo dejaría MB_CASE_TITLE.
    $sup = trim((string)$f['supuesto']);
    if ($sup !== '') {
        $sup = mb_strtoupper(mb_substr($sup, 0, 1, 'UTF-8'), 'UTF-8')
             . mb_strtolower(mb_substr($sup, 1, null, 'UTF-8'), 'UTF-8');
        // Los supuestos del CSD son «FRACCION X», «FRACCION IV»… y el número
        // romano no es una palabra: en minúscula queda «Fraccion x», que en un
        // papel de trabajo se lee como una errata.
        $sup = preg_replace_callback('/\b(?:i{1,3}|iv|vi{0,3}|ix|x{1,3}|xi{1,3}|xiv|xv)\b/u',
                                     fn($m) => mb_strtoupper($m[0], 'UTF-8'), $sup);
    }
    return ['gravedad' => 4, 'clave' => 'art69',
            'etiqueta' => $sup !== '' ? $sup : 'Artículo 69',
            'resumen'  => 'Aparece en los listados del artículo 69.'];
}

/**
 * El veredicto negativo, redactado según lo que de verdad se consultó.
 *
 * No es una constante porque su valor depende de la cobertura: mientras falten
 * listas por cargar, «No aparece» a secas es una afirmación más ancha que los
 * datos que la respaldan. La etiqueta viaja también al CSV, que es lo que se
 * pega en el papel de trabajo y sobrevive a la pantalla.
 */
function lote_no_aparece(): array
{
    static $v = null;
    if ($v !== null) return $v;

    $cob = cobertura();
    if ($cob['completa']) {
        return $v = ['gravedad' => 6, 'clave' => 'no_aparece', 'etiqueta' => 'No aparece',
                     'resumen'  => 'No fue localizado en los listados del 69, 69-B ni 69-B Bis.'];
    }
    $cubiertos = cobertura_articulos_cubiertos();
    $texto = $cubiertos ? cobertura_articulos_texto($cubiertos) : 'ninguna lista';
    return $v = [
        'gravedad' => 6, 'clave' => 'no_aparece',
        'etiqueta' => 'No aparece en ' . $texto,
        'resumen'  => 'No fue localizado en ' . $texto . '. El resto de los listados '
                    . 'todavía no está cargado, así que sobre ellos no se afirma nada.',
    ];
}

/* ==========================================================================
   Entrada
   ========================================================================== */

/** Parte el texto pegado. Acepta salto de línea, coma, punto y coma o espacio. */
function lote_partir(string $texto): array
{
    $piezas = preg_split('/[\s,;]+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY);
    return $piezas ?: [];
}

/**
 * Saca los RFC de un archivo subido.
 *
 * No se toma «la primera columna» a secas: el contador sube su listado de
 * proveedores entero, con número, nombre y saldo. Se puntúa cada columna por
 * cuántos RFC válidos tiene y gana la mejor.
 */
function lote_rfc_de_archivo(string $ruta): array
{
    $bruto = file_get_contents($ruta);
    if ($bruto === false || $bruto === '') return ['rfcs' => [], 'motivo' => 'El archivo llegó vacío.'];

    // Excel en Windows guarda en ANSI (windows-1252) salvo que se le pida otra
    // cosa. Leerlo como UTF-8 rompe los RFC con Ñ, que son 98 en el listado del
    // 69-B. Se detecta y se convierte.
    if (!mb_check_encoding($bruto, 'UTF-8')) {
        $bruto = mb_convert_encoding($bruto, 'UTF-8', 'Windows-1252');
    }
    $bruto = preg_replace('/^\xEF\xBB\xBF/', '', $bruto);   // BOM que pone el propio Excel

    $tmp = fopen('php://temp', 'r+');
    fwrite($tmp, $bruto);
    rewind($tmp);

    $filas = [];
    while (count($filas) < LOTE_MUESTRA && ($f = fgetcsv($tmp, 0, ',', '"', '\\')) !== false) {
        $filas[] = $f;
    }
    // .txt de una sola columna: no hay nada que puntuar.
    $columnas = max(array_map('count', $filas ?: [[]]));
    if ($columnas <= 1) {
        rewind($tmp);
        $todo = stream_get_contents($tmp);
        fclose($tmp);
        return ['rfcs' => lote_partir($todo), 'motivo' => ''];
    }

    $puntos = array_fill(0, $columnas, 0);
    foreach ($filas as $f) {
        foreach ($f as $i => $celda) {
            if (isset($puntos[$i]) && csv_sat_rfc($celda)['valido']) $puntos[$i]++;
        }
    }
    $mejor = array_keys($puntos, max($puntos))[0];
    if ($puntos[$mejor] === 0) {
        fclose($tmp);
        return ['rfcs' => [], 'motivo' => 'No encontré ninguna columna con RFC en el archivo.'];
    }

    rewind($tmp);
    $rfcs = []; $fila = 0;
    while (($f = fgetcsv($tmp, 0, ',', '"', '\\')) !== false) {
        $celda = isset($f[$mejor]) ? trim((string)$f[$mejor]) : '';
        $fila++;
        if ($celda === '') continue;
        // La primera fila suele ser el encabezado —la columna se eligió por
        // cuántos RFC válidos tiene, así que si esta no lo es, es el título—.
        // Se descarta en silencio: contarla como error haría que todo lote
        // subido desde Excel enseñara una entrada inválida que no lo es.
        // A partir de la segunda fila, lo que no valida SÍ se reporta: un RFC
        // mal tecleado tiene que verse, no desaparecer.
        if ($fila === 1 && !csv_sat_rfc($celda)['valido']) continue;
        $rfcs[] = $celda;
    }
    fclose($tmp);

    return ['rfcs' => $rfcs, 'motivo' => '', 'columna' => $mejor + 1];
}

/* ==========================================================================
   Consulta
   ========================================================================== */

/** Situación vigente de cada RFC, en trozos para no armar un IN gigante. */
function lote_consultar(array $rfcs): array
{
    $filas = [];
    foreach (array_chunk($rfcs, LOTE_TROZO) as $trozo) {
        // Consultas preparadas de verdad (EMULATE_PREPARES está en false): cada
        // marcador viaja al servidor, así que 5 000 en una sentencia sería
        // pedirle al servidor que prepare una sentencia de 5 000 parámetros.
        $huecos = implode(',', array_fill(0, count($trozo), '?'));
        $st = bd()->prepare("
            SELECT e.rfc, e.lista, e.proc_hash, e.situacion, e.supuesto, e.nombre,
                   e.tipo_persona, e.entidad, e.proc_texto, e.valido_desde
            FROM estatus e
            WHERE e.vigente = 1 AND e.rfc IN ($huecos)");
        $st->execute($trozo);
        foreach ($st->fetchAll() as $f) $filas[] = $f;
    }
    return $filas;
}

/**
 * Versión vigente de cada lista: la que se cita como «datos al …».
 *
 * Se toma el último snapshot procesado, no MAX(fecha_archivo): en el Artículo
 * 69 esa columna es NULL en todas las filas y el máximo no diría nada.
 */
function lote_listas_cargadas(): array
{
    $q = bd()->query("
        SELECT s.lista, s.fecha_archivo, s.fecha_servidor, s.descargado_en
        FROM snapshots s
        JOIN (SELECT lista, MAX(id) ultimo FROM snapshots
              WHERE procesado_en IS NOT NULL GROUP BY lista) u ON u.ultimo = s.id
        ORDER BY s.lista");
    $mapa = [];
    foreach ($q->fetchAll() as $s) $mapa[$s['lista']] = $s;
    return $mapa;
}

function lote_etiqueta_lista(string $clave): string
{
    return FUENTES_CATALOGO[$clave][2] ?? $clave;
}

/** "2026-05-31" -> "31 de mayo de 2026" */
function lote_fecha_larga(?string $iso): string
{
    static $meses = [1=>'enero','febrero','marzo','abril','mayo','junio','julio',
                     'agosto','septiembre','octubre','noviembre','diciembre'];
    if (!$iso) return '';
    $t = strtotime($iso);
    return $t ? sprintf('%d de %s de %d', (int)date('j',$t), $meses[(int)date('n',$t)], (int)date('Y',$t)) : $iso;
}

function registrar_bitacora(int $cantidad, string $resultado): ?int
{
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        bd()->prepare("INSERT INTO bitacora (usuario,ip,origen,rfc_consultado,cantidad,resultado,consultado_en)
                       VALUES (?,?,'lote',NULL,?,?,?)")
            ->execute([ACCESO_USUARIO, $ip ? @inet_pton($ip) : null,
                       $cantidad, mb_substr($resultado, 0, 40), date('Y-m-d H:i:s')]);
        return (int)bd()->lastInsertId();
    } catch (Throwable $e) { return null; }   // la bitácora no tumba la consulta
}

/* ==========================================================================
   Proceso
   ========================================================================== */

$listo = false; $errorBD = '';
try { bd()->query("SELECT 1 FROM estatus LIMIT 1"); $listo = true; }
catch (Throwable $e) { $errorBD = $e->getMessage(); }

$pegado    = (string)($_POST['rfcs'] ?? '');
$accion    = (string)($_POST['accion'] ?? '');
$aviso     = '';
$procesado = false;
$entrada   = [];

if ($listo && $accion !== '' && hash_equals($_SESSION['token'] ?? '', (string)($_POST['token'] ?? ''))) {

    $entrada = lote_partir($pegado);

    // Archivo subido: se suma a lo pegado, no lo sustituye.
    if (!empty($_FILES['archivo']['tmp_name']) && is_uploaded_file($_FILES['archivo']['tmp_name'])) {
        if ((int)$_FILES['archivo']['size'] > LOTE_MAX_BYTES) {
            $aviso = 'El archivo pesa más de 2 MB. Pega los RFC o parte el archivo.';
        } else {
            $leido = lote_rfc_de_archivo($_FILES['archivo']['tmp_name']);
            if ($leido['motivo'] !== '') $aviso = $leido['motivo'];
            $entrada = array_merge($entrada, $leido['rfcs']);
            if (!empty($leido['columna'])) {
                $aviso = 'Del archivo se tomó la columna ' . (int)$leido['columna'] . ', que es la que trae RFC.';
            }
        }
    }

    if (count($entrada) > LOTE_TOPE) {
        $aviso = 'Son ' . number_format(count($entrada)) . ' RFC y el tope por lote es '
               . number_format(LOTE_TOPE) . '. Pártelo en dos.';
    } elseif ($entrada) {
        $procesado = true;
    } elseif ($aviso === '') {
        $aviso = 'No llegó ningún RFC.';
    }
}

$validos = $invalidos = []; $duplicados = 0;
$porRfc = []; $ordenados = []; $sinHallazgo = []; $listasCargadas = []; $folio = null;

if ($procesado) {
    /* --- normalizar y separar en cubetas ------------------------------- */
    $vistos = [];
    foreach ($entrada as $bruto) {
        $n = csv_sat_rfc($bruto);
        if (!$n['valido']) {
            $invalidos[] = ['dado' => $bruto, 'motivo' => $n['motivo']];
            continue;
        }
        if (isset($vistos[$n['rfc']])) { $duplicados++; continue; }
        $vistos[$n['rfc']] = true;
        $validos[] = $n['rfc'];
    }

    $listasCargadas = lote_listas_cargadas();

    /* --- consultar y agrupar por RFC ----------------------------------- */
    foreach ($validos as $r) $porRfc[$r] = ['rfc' => $r, 'nombre' => '', 'apariciones' => [],
                                            'veredicto' => lote_no_aparece()];

    foreach (lote_consultar($validos) as $f) {
        $r = $f['rfc'];
        if (!isset($porRfc[$r])) continue;
        $f['clasificacion'] = lote_clasificar($f);
        $porRfc[$r]['apariciones'][] = $f;
        if ($porRfc[$r]['nombre'] === '' && $f['nombre'] !== '') $porRfc[$r]['nombre'] = $f['nombre'];
    }

    foreach ($porRfc as $r => &$reg) {
        if (!$reg['apariciones']) continue;

        // Un expediente puede verse en dos archivos: el listado completo y el
        // suelto de su situación comparten el oficio de presunción, así que
        // comparten proc_hash. En el Artículo 69 no hay oficio, y ahí lo que
        // distingue un supuesto de otro es la propia lista.
        $grupos = [];
        foreach ($reg['apariciones'] as $a) {
            $clave = $a['proc_hash'] !== '' ? $a['proc_hash'] : $a['lista'] . '|' . $a['supuesto'];
            $grupos[$clave][] = $a;
        }
        $reg['grupos'] = $grupos;

        $peor = null; $filaPeor = null;
        foreach ($reg['apariciones'] as $a) {
            if ($peor === null || $a['clasificacion']['gravedad'] < $peor['gravedad']) {
                $peor = $a['clasificacion'];
                $filaPeor = $a;
            }
        }
        $reg['veredicto'] = $peor;
        // La fecha que se enseña es la de la aparición que manda en el
        // veredicto, no la de la primera que devolvió la base: si un RFC está
        // en Firmes desde 2014 y entró como presunto el mes pasado, la fecha
        // que le importa al contador es la del presunto.
        $reg['desde'] = $filaPeor['valido_desde'] ?? '';
    }
    unset($reg);

    /* --- ordenar: lo grave arriba, lo que no aparece al final ---------- */
    $ordenados = array_values(array_filter($porRfc, fn($r) => $r['veredicto']['gravedad'] < 6));
    usort($ordenados, fn($a, $b) => [$a['veredicto']['gravedad'], $a['nombre']]
                                <=> [$b['veredicto']['gravedad'], $b['nombre']]);
    $sinHallazgo = array_values(array_filter($porRfc, fn($r) => $r['veredicto']['gravedad'] === 6));

    // Con hallazgo = los que tienen algo adverso. Desvirtuado y sentencia
    // favorable aparecen, pero no cuentan como hallazgo: es justo la distinción
    // que esta pantalla existe para no perder.
    $conHallazgo = count(array_filter($ordenados, fn($r) => $r['veredicto']['gravedad'] <= 4));
    // Un registro por lote, no uno por RFC ni uno más al descargar el CSV: la
    // descarga es el mismo lote visto otra vez, no una consulta nueva.
    if ($accion === 'consultar') {
        $folio = registrar_bitacora(count($validos), "$conHallazgo con hallazgo de " . count($validos));
    }
}

/* ==========================================================================
   Descarga CSV
   ========================================================================== */
if ($procesado && $accion === 'csv') {
    $nombre = 'listas-sat-lote-' . date('Ymd-Hi') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');

    $sal = fopen('php://output', 'w');
    // BOM. Sin él, Excel en Windows abre el archivo como ANSI y parte los
    // acentos y las Ñ de los nombres. Es el mismo problema de codificación que
    // ya está resuelto en la lectura, ahora en la escritura.
    fwrite($sal, "\xEF\xBB\xBF");

    fputcsv($sal, ['RFC','NOMBRE','VEREDICTO','LISTA','SITUACION','SUPUESTO','TIPO_PERSONA',
                   'ENTIDAD','OFICIO','VIGENTE_DESDE','FECHA_LISTA_SAT','CONSULTADO_EN'],
            ',', '"', '\\');

    $ahora = date('Y-m-d H:i:s');
    foreach (array_merge($ordenados, $sinHallazgo) as $reg) {
        if (!$reg['apariciones']) {
            // La misma etiqueta que en pantalla: si falta cobertura, el CSV lo
            // dice. Es el archivo que acaba pegado en el papel de trabajo y
            // sobrevive a la pantalla donde salía el aviso.
            fputcsv($sal, [$reg['rfc'], '', $reg['veredicto']['etiqueta'],
                           '', '', '', '', '', '', '', '', $ahora],
                    ',', '"', '\\');
            continue;
        }
        foreach ($reg['apariciones'] as $a) {
            $s = $listasCargadas[$a['lista']] ?? [];
            fputcsv($sal, [
                $reg['rfc'],
                $a['nombre'],
                $reg['veredicto']['etiqueta'],
                lote_etiqueta_lista($a['lista']),
                $a['situacion'],
                $a['supuesto'],
                $a['tipo_persona'] === 'M' ? 'Moral' : ($a['tipo_persona'] === 'F' ? 'Física' : ''),
                $a['entidad'],
                $a['proc_texto'],
                $a['valido_desde'],
                // Vacía a propósito cuando la lista no declara fecha dentro del
                // archivo: rellenarla con la del servidor es lo que convierte
                // una evidencia en un dato inventado.
                $s['fecha_archivo'] ?? '',
                $ahora,
            ], ',', '"', '\\');
        }
    }
    fclose($sal);
    exit;
}

$totalConsultados = count($validos);
$conHallazgo = $procesado ? count(array_filter($ordenados, fn($r) => $r['veredicto']['gravedad'] <= 4)) : 0;
$sinEfecto   = $procesado ? count(array_filter($ordenados, fn($r) => $r['veredicto']['gravedad'] === 5)) : 0;
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consulta por lote — Listas del SAT</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="../css/portal.css">
<style>
  .caja{ padding:20px 22px; background:#fff; border:1px solid var(--rule);
         border-radius:10px; margin-bottom:18px; }
  .campo{ display:flex; flex-direction:column; gap:6px; margin-bottom:14px; }
  .campo label{ font-size:11px; font-weight:700; letter-spacing:.08em;
                text-transform:uppercase; color:var(--mut); }
  textarea{ font:inherit; font-family:ui-monospace,Consolas,monospace; font-size:13.5px;
            padding:11px 12px; border:1px solid var(--rule); border-radius:7px;
            min-height:150px; resize:vertical; color:var(--ink); background:#fff; }
  textarea:focus{ outline:none; border-color:var(--acc); box-shadow:0 0 0 3px rgba(29,111,165,.16); }
  .fila-acciones{ display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
  .btn-buscar{ font:inherit; font-size:14.5px; font-weight:600; color:#fff; background:var(--acc);
               border:0; border-radius:7px; padding:11px 22px; cursor:pointer; }
  .btn-buscar:hover{ background:#17608F; }
  .btn-csv{ font:inherit; font-size:13.5px; font-weight:600; color:var(--acc); background:#fff;
            border:1px solid var(--acc); border-radius:7px; padding:9px 16px; cursor:pointer; }
  .btn-csv:hover{ background:var(--soft); }
  input[type=file]{ font-size:13px; color:var(--mut); }

  .marcador{ display:flex; gap:28px; flex-wrap:wrap; padding:20px 24px;
             background:var(--soft); border-radius:10px; margin-bottom:18px; }
  .marcador div b{ display:block; font-size:26px; line-height:1.15; color:var(--navy); }
  .marcador div span{ font-size:11.5px; letter-spacing:.06em; text-transform:uppercase; color:var(--mut); }
  .marcador .malo b{ color:#8C2733; }
  .marcador .bien b{ color:#2E7D4F; }

  table.datos{ width:100%; border-collapse:collapse; font-size:13.5px; background:#fff;
               border:1px solid var(--rule); border-radius:10px; overflow:hidden; }
  table.datos th{ text-align:left; font-size:11px; letter-spacing:.08em; text-transform:uppercase;
                  color:var(--mut); padding:11px 12px; background:#F7F9FB;
                  border-bottom:1px solid var(--rule); }
  table.datos td{ padding:11px 12px; border-bottom:1px solid var(--rule); vertical-align:top; }
  table.datos tr:last-child td{ border-bottom:0; }
  .mono{ font-family:ui-monospace,Consolas,monospace; font-size:12.5px; }
  .tenue{ color:var(--mut); font-size:12.5px; }
  .etq{ display:inline-block; padding:3px 10px; border-radius:999px; font-size:11.5px; font-weight:700; }
  .g1{ background:#FBEEF0; color:#8C2733; }   /* definitivo */
  .g2{ background:#FDF0D5; color:#8A5B00; }   /* presunto */
  .g3{ background:#F3EEF8; color:#5B3E86; }   /* bis */
  .g4{ background:#EAF3FB; color:#1D6FA5; }   /* art 69 */
  .g5{ background:#EDF7F0; color:#2E7D4F; }   /* sin efecto adverso */
  tr.f1{ background:#FFFAFA; }
  .alerta{ padding:12px 14px; border-radius:8px; background:#FBEEF0; border:1px solid #E6B9BF;
           color:#8C2733; font-size:13.5px; margin-bottom:16px; }
  .aviso{ padding:12px 14px; border-radius:8px; background:#FDF6E3; border:1px solid #E8D9A8;
          color:#7A5D00; font-size:13.5px; margin-bottom:16px; }
  details.plegable{ margin-top:18px; }
  details.plegable summary{ cursor:pointer; font-size:13.5px; color:var(--acc); padding:8px 0; }
  .rejilla-rfc{ display:flex; flex-wrap:wrap; gap:6px 14px; padding:14px 0 4px;
                font-family:ui-monospace,Consolas,monospace; font-size:12.5px; color:var(--mut); }
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
        <b>Consulta por lote</b>
        <span>Artículo 69 · 69-B · 69-B Bis</span>
      </div>
    </div>
    <p class="cabecera-contacto">
      <a href="consulta.php">Consultar un RFC</a> · <a href="alertas.php">Alertas</a> ·
      <a href="tipo-cambio.php">Tipo de cambio</a> · <a href="equivalencias.php">Equivalencias</a> ·
      <a href="index.php">Administración</a>
    </p>
  </div>
</header>

<main class="seccion">
  <div class="contenedor">

    <?php if (!$listo): ?>
      <div class="alerta"><b>Todavía no hay datos cargados.</b><br>
        Ve a <a href="index.php">Administración</a> y completa la puesta en marcha.</div>
    <?php else: ?>

    <?php if ($aviso): ?><div class="aviso"><?= esc($aviso) ?></div><?php endif; ?>

    <?php /* ---- cobertura ----
         Va arriba y en rojo a propósito. Un negativo solo vale lo que vale la
         cobertura, y aquí lo que se consulta es la cartera de clientes del
         despacho: leer «no aparece» creyendo que se han mirado los tres
         artículos cuando solo se ha mirado uno es peor que no consultar. */ ?>
    <?php $cob = cobertura(); if (!$cob['completa']):
      $cubiertos = cobertura_articulos_cubiertos(); ?>
      <div class="alerta">
        <b>Esta consulta no cubre los tres artículos.</b><br>
        Falta<?= count($cob['articulos_incompletos']) > 1 ? 'n' : '' ?> por cargar
        <b><?= esc(cobertura_articulos_texto($cob['articulos_incompletos'])) ?></b>
        (<?= count($cob['faltan']) ?> de <?= count($cob['listas']) ?> listas del catálogo).
        Aquí un «no aparece» significa
        <?= $cubiertos
              ? 'únicamente <b>que no aparece en ' . esc(cobertura_articulos_texto($cubiertos)) . '</b>'
              : '<b>que no se consultó ninguna lista</b>' ?>.
        Se cargan desde <a href="index.php">Administración</a>, una por una.
      </div>
    <?php endif; ?>

    <form class="caja" method="post" action="lote.php" enctype="multipart/form-data">
      <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
      <input type="hidden" name="accion" value="consultar">
      <div class="campo">
        <label for="rfcs">Pega aquí los RFC</label>
        <textarea id="rfcs" name="rfcs" placeholder="AAA080808HL8&#10;ÑAÑ140114GY4&#10;XAXX010101000"><?= esc($pegado) ?></textarea>
        <span class="tenue">Uno por línea, o separados por coma, punto y coma o espacio.
          Da igual si traen guiones. Hasta <?= number_format(LOTE_TOPE) ?> por lote.</span>
      </div>
      <div class="fila-acciones">
        <button class="btn-buscar">Consultar el lote</button>
        <label class="tenue" for="archivo">o sube un archivo:</label>
        <input type="file" id="archivo" name="archivo" accept=".csv,.txt">
      </div>
      <p class="tenue" style="margin:10px 0 0">
        Puedes subir tu listado de proveedores tal cual, con nombres y saldos:
        se busca sola la columna que trae los RFC.
      </p>
    </form>

    <?php if ($procesado): ?>

      <div class="marcador">
        <div><b><?= number_format($totalConsultados) ?></b><span>Consultados</span></div>
        <div class="malo"><b><?= number_format($conHallazgo) ?></b><span>Con hallazgo</span></div>
        <div class="bien"><b><?= number_format(count($sinHallazgo)) ?></b><span>No aparecen</span></div>
        <?php if ($sinEfecto): ?>
          <div><b><?= number_format($sinEfecto) ?></b><span>Sin efecto adverso</span></div>
        <?php endif; ?>
        <?php if ($invalidos): ?>
          <div><b><?= number_format(count($invalidos)) ?></b><span>No son RFC</span></div>
        <?php endif; ?>
        <?php if ($duplicados): ?>
          <div><b><?= number_format($duplicados) ?></b><span>Repetidos</span></div>
        <?php endif; ?>
      </div>

      <form method="post" action="lote.php" style="margin:0 0 18px">
        <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
        <input type="hidden" name="accion" value="csv">
        <input type="hidden" name="rfcs" value="<?= esc(implode("\n", $validos)) ?>">
        <button class="btn-csv">Descargar el resultado en CSV</button>
      </form>

      <?php /* ------------------- los que aparecen ------------------- */ ?>
      <?php if ($ordenados): ?>
        <h2 class="seccion-titulo seccion-titulo-2">Aparecen en los listados</h2>
        <table class="datos">
          <tr><th>RFC</th><th>Nombre</th><th>Veredicto</th><th>Dónde aparece</th><th>Desde</th></tr>
          <?php foreach (array_slice($ordenados, 0, LOTE_EN_PANTALLA) as $reg): $v = $reg['veredicto']; ?>
            <tr class="<?= $v['gravedad'] <= 2 ? 'f1' : '' ?>">
              <td class="mono"><a href="consulta.php?rfc=<?= esc($reg['rfc']) ?>"><?= esc($reg['rfc']) ?></a></td>
              <td><?= esc($reg['nombre'] !== '' ? $reg['nombre'] : '—') ?></td>
              <td>
                <span class="etq g<?= (int)$v['gravedad'] ?>"><?= esc($v['etiqueta']) ?></span>
                <div class="tenue" style="margin-top:5px"><?= esc($v['resumen']) ?></div>
              </td>
              <td class="tenue">
                <?php foreach ($reg['grupos'] as $grupo):
                        $uno = $grupo[0]; ?>
                  <div style="margin-bottom:6px">
                    <?= esc(lote_etiqueta_lista($uno['lista'])) ?>
                    <?php if (count($grupo) > 1): ?>
                      <br><span style="opacity:.75">y <?= count($grupo) - 1 ?> archivo<?= count($grupo) > 2 ? 's' : '' ?>
                      más del mismo expediente</span>
                    <?php endif; ?>
                    <?php if ($uno['proc_texto']): ?>
                      <br><span style="opacity:.75"><?= esc(mb_substr($uno['proc_texto'], 0, 60)) ?></span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </td>
              <td class="tenue"><?= esc($reg['desde'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <?php if (count($ordenados) > LOTE_EN_PANTALLA): ?>
          <p class="tenue" style="margin-top:10px">
            Se muestran los <?= number_format(LOTE_EN_PANTALLA) ?> primeros de
            <?= number_format(count($ordenados)) ?>, ordenados de más grave a menos.
            El CSV los trae todos.
          </p>
        <?php endif; ?>
      <?php endif; ?>

      <?php /* ------------------- los que no aparecen ------------------- */ ?>
      <?php if ($sinHallazgo): ?>
        <details class="plegable">
          <summary><?= number_format(count($sinHallazgo)) ?>
            <?= count($sinHallazgo) === 1 ? 'RFC no fue localizado' : 'RFC no fueron localizados' ?>
            en <?php $cub = cobertura_articulos_cubiertos();
                echo $cob['completa'] ? 'ninguno de los tres artículos'
                   : ($cub ? esc(cobertura_articulos_texto($cub)) : 'ninguna lista cargada'); ?>
            — ver <?= count($sinHallazgo) === 1 ? 'cuál' : 'cuáles' ?></summary>
          <div class="rejilla-rfc">
            <?php foreach ($sinHallazgo as $reg): ?><span><?= esc($reg['rfc']) ?></span><?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>

      <?php /* ------------------- los que no son RFC ------------------- */ ?>
      <?php if ($invalidos): ?>
        <details class="plegable">
          <summary><?= number_format(count($invalidos)) ?>
            <?= count($invalidos) === 1 ? 'entrada no se pudo consultar' : 'entradas no se pudieron consultar' ?>
            — ver por qué</summary>
          <table class="datos" style="margin-top:12px">
            <tr><th>Lo que llegó</th><th>Motivo</th></tr>
            <?php foreach (array_slice($invalidos, 0, 200) as $i): ?>
              <tr>
                <td class="mono"><?= esc(mb_substr((string)$i['dado'], 0, 40)) ?></td>
                <td class="tenue"><?php
                  echo match ($i['motivo']) {
                      'vacio'      => 'Venía vacío.',
                      'formato'    => 'No tiene forma de RFC.',
                      'suprimido'  => 'Es un registro que el SAT publicó tachado, no un RFC.',
                      default      => 'No mide 12 ni 13 caracteres.',
                  }; ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
          <?php if (count($invalidos) > 200): ?>
            <p class="tenue">y <?= number_format(count($invalidos) - 200) ?> más.</p>
          <?php endif; ?>
        </details>
      <?php endif; ?>

      <?php /* ------------------- qué se consultó ------------------- */ ?>
      <h2 class="seccion-titulo seccion-titulo-2">Qué se consultó</h2>
      <table class="datos">
        <tr><th>Lista</th><th>Datos al</th><th>Descargada</th></tr>
        <?php foreach ($listasCargadas as $clave => $s): ?>
          <tr>
            <td><?= esc(lote_etiqueta_lista($clave)) ?></td>
            <td class="tenue">
              <?php if ($s['fecha_archivo']): ?>
                <?= esc(lote_fecha_larga($s['fecha_archivo'])) ?>
              <?php else: ?>
                <!-- Los archivos del Art. 69 no traen la línea "Información
                     actualizada al…". Se dice, no se rellena con la del servidor. -->
                <em>el archivo no declara fecha</em>
              <?php endif; ?>
            </td>
            <td class="tenue"><?= esc(substr((string)$s['descargado_en'], 0, 10)) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>

      <p class="nota-legal">
        <b>Qué acredita esta consulta y qué no.</b>
        El resultado dice si cada RFC figura en la versión de los listados que
        aparece arriba, con la fecha de cada una. Un RFC que no figura
        <b>no queda certificado como libre de cualquier supuesto</b>: solo consta
        que no fue localizado en esos archivos.
        <?php if (!$cob['completa']): ?>
          En esta consulta, además, <b>no se miró
          <?= esc(cobertura_articulos_texto($cob['articulos_incompletos'])) ?></b>,
          porque <?= count($cob['faltan']) ?> de <?= count($cob['listas']) ?> listas
          del catálogo todavía no están cargadas.
        <?php endif; ?>
        <?php if ($folio): ?>
          Consulta registrada en la bitácora con folio <b>L-<?= str_pad((string)$folio, 6, '0', STR_PAD_LEFT) ?></b>
          el <?= esc(date('d/m/Y')) ?> a las <?= esc(date('H:i')) ?> h.
        <?php endif; ?>
        Los listados incluyen personas físicas: cada consulta queda registrada.
      </p>

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
