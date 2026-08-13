<?php
/* ============================================================================
   fuentes.php — descubrimiento de los archivos que publica el SAT.

   NO lleva una lista fija de URLs a propósito. El SAT ya movió estos archivos
   una vez: las direcciones de omawww.sat.gob.mx que circulan por ahí siguen
   respondiendo 200, pero sirven datos de enero de 2026. Un ingestor con URLs
   quemadas no falla: sirve información vieja en silencio, que es peor.

   Aquí se lee la página índice en cada corrida y se comparan los archivos
   encontrados contra un catálogo de lo que se espera. Si algo desaparece,
   cambia de nombre o aparece de más, se reporta.

   Verificado el 12/08/2026: la página índice trae los enlaces en el HTML
   crudo, sin JavaScript, así que basta una petición normal.
   ============================================================================ */

const FUENTES_INDICE = 'https://www.sat.gob.mx/minisitio/DatosAbiertos/contribuyentes_publicados.html';
const FUENTES_UA     = 'Mozilla/5.0 (compatible; ISS-ListasSAT/1.0; +https://insusermx.com)';
const FUENTES_TIMEOUT = 45;

/* Catálogo de lo que esperamos encontrar.
   clave interna => [grupo, nombre de archivo, etiqueta legible]
   El emparejamiento es por NOMBRE DE ARCHIVO, no por ruta completa: si el SAT
   reorganiza carpetas seguimos encontrándolo; si cambia el nombre, salta. */
const FUENTES_CATALOGO = [
    'art69.firmes'           => ['art69',      'Firmes.csv',                          'Art. 69 · Firmes'],
    'art69.no_localizados'   => ['art69',      'No_localizados.csv',                  'Art. 69 · No localizados'],
    'art69.exigibles'        => ['art69',      'Exigibles.csv',                       'Art. 69 · Exigibles'],
    'art69.cancelados'       => ['art69',      'Cancelados.csv',                      'Art. 69 · Cancelados'],
    'art69.sentencias'       => ['art69',      'Sentencias.csv',                      'Art. 69 · Sentencias'],
    'art69.csd_sin_efectos'  => ['art69',      'CSDsinefectos.csv',                   'Art. 69 · CSD sin efectos'],
    'art69.entes_publicos'   => ['art69',      'EntespublicosydeGobiernoomisos.csv',  'Art. 69 · Entes públicos omisos'],

    'art69b.completo'        => ['art69b',     'Listado_completo_69-B.csv',           'Art. 69-B · Listado completo'],
    'art69b.presuntos'       => ['art69b',     'Presuntos.csv',                       'Art. 69-B · Presuntos'],
    'art69b.definitivos'     => ['art69b',     'Definitivos.csv',                     'Art. 69-B · Definitivos'],
    'art69b.desvirtuados'    => ['art69b',     'Desvirtuados.csv',                    'Art. 69-B · Desvirtuados'],
    'art69b.sent_favorables' => ['art69b',     'SentenciasFavorables.csv',            'Art. 69-B · Sentencias favorables'],

    'bis.completo'           => ['art69b_bis', 'Listado_69_B_Bis_Completo.csv',       'Art. 69-B Bis · Listado completo'],
    'bis.definitivos'        => ['art69b_bis', 'Listado_69_B_Bis_Definitivo.csv',     'Art. 69-B Bis · Definitivos'],
    'bis.sent_favorables'    => ['art69b_bis', 'Listado_69_B_Bis_SentenciaFa.csv',    'Art. 69-B Bis · Sentencias favorables'],
];

/* ---------------------------------------------------------------- descarga */

function fuentes_pedir(string $url, bool $soloCabeceras = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => FUENTES_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT      => FUENTES_UA,
        CURLOPT_NOBODY         => $soloCabeceras,
        CURLOPT_HEADER         => $soloCabeceras,
        CURLOPT_ENCODING       => '',
    ]);
    $cuerpo = curl_exec($ch);
    $info   = curl_getinfo($ch);
    $err    = curl_error($ch);
    curl_close($ch);

    return [
        'ok'      => $cuerpo !== false && $info['http_code'] >= 200 && $info['http_code'] < 300,
        'codigo'  => $info['http_code'] ?? 0,
        'bytes'   => isset($info['download_content_length']) && $info['download_content_length'] > 0
                        ? (int)$info['download_content_length'] : strlen((string)$cuerpo),
        'tipo'    => $info['content_type'] ?? '',
        'url'     => $info['url'] ?? $url,
        'cuerpo'  => $soloCabeceras ? '' : (string)$cuerpo,
        'cabeceras' => $soloCabeceras ? (string)$cuerpo : '',
        'error'   => $err,
    ];
}

/* Fecha de publicación según el servidor. OJO: no es la fecha buena.
   Los archivos del 69-B llevan dentro "Información actualizada al ..." y esa
   es la que vale — el Last-Modified puede ir semanas por delante. */
function fuentes_fecha_servidor(string $cabeceras): ?string
{
    if (preg_match('/^Last-Modified:\s*(.+)$/mi', $cabeceras, $m)) {
        // Se descarta el día de la semana antes de interpretar la fecha.
        // Medido el 13/08/2026: ante "Tue, 17 Jun 2026" —el 17 es miércoles—
        // PHP no da error, avanza hasta el martes siguiente y devuelve el 23.
        // Seis días de diferencia, sin aviso, en la fecha que se cita como
        // evidencia en las constancias. El nombre del día no aporta nada que no
        // esté ya en el número: sobra, y solo puede estropear la lectura.
        // strtotime y DateTimeImmutable::createFromFormat se comportan igual.
        $fecha = preg_replace('/^[A-Za-z]{3,9},\s*/', '', trim($m[1]));
        $t = strtotime($fecha);
        if ($t) return gmdate('Y-m-d H:i:s', $t);
    }
    return null;
}

/* ------------------------------------------------------------ extracción */

function fuentes_extraer_enlaces(string $html): array
{
    $enlaces = [];
    // href="...csv|xls|xlsx|zip|txt", absolutos o relativos
    if (preg_match_all('/href\s*=\s*"([^"]+\.(?:csv|xlsx?|zip|txt))"/i', $html, $m)) {
        foreach ($m[1] as $u) {
            $u = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
            if (!preg_match('#^https?://#i', $u)) continue;   // solo absolutos
            $enlaces[$u] = basename(parse_url($u, PHP_URL_PATH) ?? '');
        }
    }
    return $enlaces;   // url => nombre de archivo
}

/* Entre varias URLs con el mismo nombre de archivo, la buena es la que está
   bajo Documents_*: el SAT deja copias en rutas con fecha que no se
   actualizan. Verificado con Sentencias.csv, que aparece dos veces. */
function fuentes_preferir(array $urls): string
{
    foreach ($urls as $u) if (stripos($u, '/Documents_') !== false) return $u;
    return $urls[0];
}

/* ------------------------------------------------------------ descubrir */

function fuentes_descubrir(): array
{
    $r = fuentes_pedir(FUENTES_INDICE);
    if (!$r['ok']) {
        return ['ok' => false, 'motivo' => "La página índice no respondió (HTTP {$r['codigo']}) {$r['error']}",
                'listas' => [], 'faltantes' => [], 'desconocidas' => [], 'ambiguas' => []];
    }

    $enlaces = fuentes_extraer_enlaces($r['cuerpo']);
    if (!$enlaces) {
        return ['ok' => false, 'motivo' => 'La página índice respondió pero no trae enlaces a archivos. '
                . 'Puede que hayan cambiado el maquetado.',
                'listas' => [], 'faltantes' => [], 'desconocidas' => [], 'ambiguas' => []];
    }

    // agrupar por nombre de archivo
    $porNombre = [];
    foreach ($enlaces as $url => $nombre) $porNombre[$nombre][] = $url;

    $listas = $faltantes = $ambiguas = [];
    $usadas = [];

    foreach (FUENTES_CATALOGO as $clave => [$grupo, $archivo, $etiqueta]) {
        if (!isset($porNombre[$archivo])) { $faltantes[$clave] = "$etiqueta ($archivo)"; continue; }
        $urls = $porNombre[$archivo];
        $url  = fuentes_preferir($urls);
        if (count($urls) > 1) $ambiguas[$clave] = $urls;
        $listas[$clave] = ['grupo' => $grupo, 'etiqueta' => $etiqueta, 'archivo' => $archivo, 'url' => $url];
        foreach ($urls as $u) $usadas[$u] = true;
    }

    $desconocidas = [];
    foreach ($enlaces as $url => $nombre) if (!isset($usadas[$url])) $desconocidas[$url] = $nombre;

    return ['ok' => true, 'motivo' => '', 'listas' => $listas, 'faltantes' => $faltantes,
            'desconocidas' => $desconocidas, 'ambiguas' => $ambiguas,
            'total_enlaces' => count($enlaces)];
}
