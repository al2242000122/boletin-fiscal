<?php
/* ============================================================================
   dof_eq.php — equivalencias mensuales de ~69 monedas contra el dólar.

   Banxico las publica en el DOF entre los días 4 y 7 del mes siguiente. Sirven
   para convertir una operación en moneda distinta del dólar: moneda → USD con
   esta tabla, y USD → MXN con el tipo de cambio diario (dof_tc.php).

   Portado del motor en Python del proyecto de conciliación. Lo que NO se porta
   es el camino de PDF con OCR: necesita tesseract y poppler, que no hay en
   hosting compartido, y no hace falta — el DOF entrega la nota en HTML.

   Dos cosas medidas el 14/08/2026 que conviene no deshacer:

   · dof.gob.mx SIN www. El certificado TLS solo cubre el dominio pelado.

   · EL NÚMERO DE LA LLAMADA AL PIE NO ES ESTABLE. En 2021 «2/» quería decir
     «expresado por mil unidades»; en 2026 «2/» es el yuan cotizado fuera de
     China continental y el «por mil» pasó a ser «3/», porque al añadir la fila
     de China las llamadas rotaron. Por eso se guarda el pie entero y se deriva
     una bandera por_mil al ingerir, en vez de dejar que quien consulte compare
     contra un número fijo. Un dong vietnamita mal interpretado se convierte
     mil veces mal.
   ============================================================================ */

require_once __DIR__ . '/bd.php';

const DOF_EQ_BUSQUEDA = 'https://dof.gob.mx/busqueda_detalle.php?BUSCAR_EN=T&TIPO_TEXTO=Y'
                      . '&busqueda_cuerpo=&vienede=avanzada&dfecha={desde}&hfecha={hasta}'
                      . '&textobusqueda={texto}';
const DOF_EQ_NOTA     = 'https://dof.gob.mx/nota_detalle.php?codigo={codigo}&fecha={fecha}&print=true';
const DOF_EQ_TEXTO    = 'EQUIVALENCIA de las monedas';
const DOF_EQ_UA       = 'Mozilla/5.0 (compatible; ISS-DOF/1.0; +https://insusermx.com)';

/* Cuántas monedas se esperan por publicación. Medido sobre los 67 periodos de
   2021-01 a 2026-07: 69 en todos. Se usa solo para avisar, no para descartar:
   si un mes trae 60, el dato entra y se dice. */
const DOF_EQ_ESPERADAS = 69;

const DOF_EQ_MESES = ['ene'=>1,'feb'=>2,'mar'=>3,'abr'=>4,'may'=>5,'jun'=>6,
                      'jul'=>7,'ago'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dic'=>12];

/* ------------------------------------------------------------------- red */

function dof_eq_pedir(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => DOF_EQ_UA, CURLOPT_ENCODING => '',
    ]);
    $cuerpo = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    return ['ok' => $cuerpo !== false && $codigo >= 200 && $codigo < 300,
            'cuerpo' => (string)$cuerpo, 'codigo' => $codigo, 'error' => $error];
}

/* --------------------------------------------------------------- lectura */

function dof_eq_sin_acentos(string $s): string
{
    return strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
                      'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
}

function dof_eq_texto_plano(string $html): string
{
    $limpio = preg_replace('/(?is)<(script|style).*?<\/\1>/', ' ', $html);
    $t = html_entity_decode(preg_replace('/(?s)<[^>]+>/', ' ', $limpio), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $t));
}

/** "…correspondiente al mes de julio de 2026" -> "2026-07" */
function dof_eq_periodo(string $texto): ?string
{
    if (preg_match('/\b(ene|feb|mar|abr|may|jun|jul|ago|sep|oct|nov|dic)[a-z]*[\s\-]+(?:de\s+)?(\d{4})\b/iu',
                   dof_eq_sin_acentos($texto), $m)) {
        $mes = DOF_EQ_MESES[mb_strtolower(mb_substr($m[1], 0, 3), 'UTF-8')] ?? null;
        if ($mes) return sprintf('%04d-%02d', (int)$m[2], $mes);
    }
    return null;
}

/**
 * Lee el pie de la nota y devuelve ['2/' => 'texto…', '3/' => 'texto…'].
 * De ahí sale qué llamada significa «por mil» EN ESTA publicación.
 */
function dof_eq_pie(string $plano): array
{
    $pie = [];
    if (preg_match_all('/(\d)\/\s+([A-ZÁÉÍÓÚ][^0-9]{10,200}?)(?=\s+\d\/|$)/u', $plano, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $pie[$x[1] . '/'] = trim($x[2]);
    }
    return $pie;
}

/** Qué llamada marca «por mil unidades» en esta publicación, o null. */
function dof_eq_llamada_por_mil(array $pie): ?string
{
    foreach ($pie as $marca => $texto) {
        if (preg_match('/por\s+mil\s+unidades/iu', dof_eq_sin_acentos($texto))) return $marca;
    }
    return null;
}

/** "Pais   Moneda 3/" -> ['pais'=>…, 'moneda'=>…, 'nota'=>'3/'|null] */
function dof_eq_partir(string $resto): ?array
{
    $resto = trim($resto);
    $nota  = null;
    if (preg_match('/\s(\d)\s*\/\s*$/u', $resto, $m)) {
        $nota  = $m[1] . '/';
        $resto = trim(mb_substr($resto, 0, mb_strpos($resto, $m[0])));
    }
    // Las columnas del HTML del DOF llegan separadas por dos o más espacios.
    $partes = preg_split('/\s{2,}/u', $resto, -1, PREG_SPLIT_NO_EMPTY);
    if (count($partes) < 2) return null;
    $pais = trim(array_shift($partes));
    return ['pais' => $pais, 'moneda' => trim(implode(' ', $partes)), 'nota' => $nota];
}

/**
 * Convierte el HTML de la nota en filas.
 * Devuelve ['periodo'=>…, 'pie'=>[…], 'por_mil'=>'3/'|null, 'filas'=>[…]]
 */
function dof_eq_interpretar(string $html): array
{
    $limpio = preg_replace('/(?is)<(script|style).*?<\/\1>/', ' ', $html);

    /* La tabla se aplana a "pais   moneda   valor" por fila. Se hace sobre las
       celdas y no sobre el texto corrido porque los nombres llevan espacios
       simples dentro ("Arabia Saudita") y solo la separación de celdas
       distingue una columna de la siguiente. */
    $lineas = [];
    if (preg_match_all('/(?is)<tr[^>]*>(.*?)<\/tr>/', $limpio, $trs)) {
        foreach ($trs[1] as $tr) {
            preg_match_all('/(?is)<t[dh][^>]*>(.*?)<\/t[dh]>/', $tr, $tds);
            $celdas = [];
            foreach ($tds[1] as $c) {
                $c = html_entity_decode(preg_replace('/(?s)<[^>]+>/', ' ', $c), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $c = trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $c)));
                if ($c !== '') $celdas[] = $c;
            }
            if (count($celdas) >= 3) $lineas[] = implode('   ', $celdas);
        }
    }

    $plano   = dof_eq_texto_plano($html);
    $pie     = dof_eq_pie($plano);
    $porMil  = dof_eq_llamada_por_mil($pie);

    $filas = [];
    foreach ($lineas as $l) {
        if (!preg_match('/^(.+?)\s+(\d{1,2}\.\d{5})\s*$/u', $l, $m)) continue;
        // Cabeceras y textos que también acaban en un número con cinco decimales.
        if (preg_match('/equivalencia|moneda extranjera|http|kilometros/iu', dof_eq_sin_acentos($m[1]))) continue;

        $p = dof_eq_partir($m[1]);
        if (!$p || $p['pais'] === '' || $p['moneda'] === '') continue;

        $filas[] = [
            'pais'    => $p['pais'],
            'moneda'  => $p['moneda'],
            'nota'    => $p['nota'],
            'por_mil' => ($porMil !== null && $p['nota'] === $porMil) ? 1 : 0,
            'valor'   => $m[2],   // cadena: el redondeo lo decide la columna DECIMAL
        ];
    }

    return ['periodo' => dof_eq_periodo($plano), 'pie' => $pie,
            'por_mil' => $porMil, 'filas' => $filas];
}

/* -------------------------------------------------------------- búsqueda */

/**
 * Busca las notas de equivalencias publicadas en un rango (dd/mm/aaaa).
 * Devuelve [['codigo'=>…, 'fecha_pub'=>'dd/mm/aaaa', 'titulo'=>…, 'periodo'=>…]]
 */
function dof_eq_buscar(string $desde, string $hasta): array
{
    $url = strtr(DOF_EQ_BUSQUEDA, ['{desde}' => $desde, '{hasta}' => $hasta,
                                   '{texto}' => rawurlencode(DOF_EQ_TEXTO)]);
    $r = dof_eq_pedir($url);
    if (!$r['ok']) return [];

    $notas = []; $vistos = [];
    preg_match_all('/nota_detalle\.php\?codigo=(\d+)[^"\'>]*fecha=([0-9\/%]+)[^>]*>(.{0,500}?)<\/a>/is',
                   $r['cuerpo'], $m, PREG_SET_ORDER);
    foreach ($m as $x) {
        if (isset($vistos[$x[1]])) continue;
        $titulo = html_entity_decode(trim(preg_replace('/\s+/u', ' ', strip_tags($x[3]))),
                                     ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (stripos(dof_eq_sin_acentos($titulo), 'equivalencia de las monedas') === false) continue;
        $vistos[$x[1]] = true;
        $notas[] = ['codigo' => $x[1], 'fecha_pub' => trim(urldecode($x[2])),
                    'titulo' => $titulo, 'periodo' => dof_eq_periodo($titulo)];
    }
    return $notas;
}

/* --------------------------------------------------------------- ingesta */

/** Descarga una nota concreta y la guarda. */
function dof_eq_ingerir(string $codigo, string $fechaPub, ?callable $log = null): array
{
    $log ??= function ($t) {};

    $r = dof_eq_pedir(strtr(DOF_EQ_NOTA, ['{codigo}' => $codigo, '{fecha}' => $fechaPub]));
    if (!$r['ok']) return ['ok' => false, 'motivo' => $r['error'] ?: "HTTP {$r['codigo']}"];

    $d = dof_eq_interpretar($r['cuerpo']);
    if (!$d['periodo']) return ['ok' => false, 'motivo' => 'no se pudo leer a qué mes corresponde la nota'];
    if (!$d['filas'])   return ['ok' => false, 'motivo' => 'la nota no trae ninguna moneda legible'];

    $fechaIso = ($t = strtotime(str_replace('/', '-', $fechaPub))) ? date('Y-m-d', $t) : null;

    bd()->prepare("INSERT INTO dof_publicaciones (periodo, codigo_dof, fecha_publicacion, notas_pie, ingresado_en)
                   VALUES (?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE codigo_dof = VALUES(codigo_dof),
                     fecha_publicacion = VALUES(fecha_publicacion), notas_pie = VALUES(notas_pie)")
        ->execute([$d['periodo'], $codigo, $fechaIso,
                   json_encode($d['pie'], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);

    $st = bd()->prepare("SELECT id FROM dof_publicaciones WHERE periodo = ?");
    $st->execute([$d['periodo']]);
    $pubId = (int)$st->fetchColumn();

    $ins = bd()->prepare("INSERT INTO dof_equivalencias
          (publicacion_id, periodo, pais, moneda, nota, por_mil, equivalencia_usd)
          VALUES (?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE equivalencia_usd = VALUES(equivalencia_usd),
            nota = VALUES(nota), por_mil = VALUES(por_mil), publicacion_id = VALUES(publicacion_id)");
    foreach ($d['filas'] as $f) {
        $ins->execute([$pubId, $d['periodo'], $f['pais'], $f['moneda'],
                       $f['nota'], $f['por_mil'], $f['valor']]);
    }

    $aviso = count($d['filas']) !== DOF_EQ_ESPERADAS
        ? sprintf(' — OJO: %d monedas, se esperaban %d', count($d['filas']), DOF_EQ_ESPERADAS) : '';
    $log(sprintf('   %s: %d monedas (código %s, publicada %s)%s',
                 $d['periodo'], count($d['filas']), $codigo, $fechaPub, $aviso));

    return ['ok' => true, 'periodo' => $d['periodo'], 'monedas' => count($d['filas']),
            'por_mil' => $d['por_mil'], 'motivo' => ''];
}

/**
 * Trae lo que falte entre dos meses.
 * El buscador pagina a 10 resultados, así que se pide por semestres: caben
 * como mucho siete notas en cada ventana.
 */
function dof_eq_sincronizar(string $desdePeriodo = '2021-01', ?string $hastaPeriodo = null,
                            ?callable $log = null, int $maxNuevos = 0): array
{
    /* $maxNuevos acota cuántas notas se traen de una vez. La serie entera son
       67 notas y 53 segundos medidos, que en una petición web de hosting
       compartido es pedir problemas. Con un tope, se pulsa varias veces y cada
       una avanza: lo que ya está no se vuelve a descargar. 0 = sin tope, para
       la consola y el cron. */
    $log ??= function ($t) {};
    $hastaPeriodo ??= date('Y-m');

    $yaEstan = [];
    foreach (bd()->query("SELECT periodo FROM dof_publicaciones")->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $yaEstan[$p] = true;
    }

    [$y1, $m1] = array_map('intval', explode('-', $desdePeriodo));
    [$y2, $m2] = array_map('intval', explode('-', $hastaPeriodo));

    $nuevos = 0; $errores = 0; $vistas = [];
    // La nota del mes M sale en M+1, así que la ventana de publicación va un
    // semestre por delante del periodo que se busca.
    for ($y = $y1; $y <= $y2 + 1; $y++) {
        foreach ([[1, 6], [7, 12]] as [$ma, $mb]) {
            $desde = sprintf('01/%02d/%04d', $ma, $y);
            $hasta = sprintf('%02d/%02d/%04d', (int)date('t', mktime(0,0,0,$mb,1,$y)), $mb, $y);
            if (strtotime(str_replace('/', '-', $desde)) > time()) continue;

            foreach (dof_eq_buscar($desde, $hasta) as $n) {
                if (isset($vistas[$n['codigo']])) continue;
                $vistas[$n['codigo']] = true;
                // El periodo del título es una estimación; la nota manda. Pero
                // sirve para no descargar lo que ya está.
                if ($n['periodo'] && isset($yaEstan[$n['periodo']])) continue;

                $r = dof_eq_ingerir($n['codigo'], $n['fecha_pub'], $log);
                if ($r['ok']) { $nuevos++; $yaEstan[$r['periodo']] = true; }
                else { $errores++; $log("   fallo en la nota {$n['codigo']}: {$r['motivo']}"); }

                if ($maxNuevos > 0 && $nuevos >= $maxNuevos) {
                    $log("   (tope de $maxNuevos alcanzado; vuelve a lanzarlo para seguir)");
                    return ['ok' => $errores === 0, 'nuevos' => $nuevos,
                            'errores' => $errores, 'quedan' => true];
                }
            }
        }
    }

    return ['ok' => $errores === 0, 'nuevos' => $nuevos, 'errores' => $errores, 'quedan' => false];
}

/* -------------------------------------------------------------- consulta */

/**
 * Equivalencia de una moneda en un periodo.
 * $paisOMoneda casa contra país o moneda, sin acentos y sin importar mayúsculas.
 */
function dof_eq_consultar(string $periodo, string $paisOMoneda): array
{
    $b = '%' . dof_eq_sin_acentos(mb_strtolower(trim($paisOMoneda), 'UTF-8')) . '%';
    $st = bd()->prepare("
        SELECT periodo, pais, moneda, nota, por_mil, equivalencia_usd
        FROM dof_equivalencias
        WHERE periodo = ?
          AND (LOWER(pais) LIKE ? OR LOWER(moneda) LIKE ?)
        ORDER BY pais, moneda");
    $st->execute([$periodo, $b, $b]);
    return $st->fetchAll();
}

/** Estado de la serie mensual. */
function dof_eq_estado(): array
{
    try {
        $r = bd()->query("SELECT MIN(periodo) a, MAX(periodo) b, COUNT(*) n
                          FROM dof_publicaciones")->fetch();
        $monedas = (int)bd()->query("SELECT COUNT(*) FROM dof_equivalencias")->fetchColumn();
    } catch (Throwable $e) {
        return ['hay' => false, 'primero' => null, 'ultimo' => null,
                'periodos' => 0, 'monedas' => 0, 'faltan' => []];
    }
    if (!$r || !$r['n']) {
        return ['hay' => false, 'primero' => null, 'ultimo' => null,
                'periodos' => 0, 'monedas' => 0, 'faltan' => []];
    }

    /* Huecos en la secuencia mensual. Un mes que falta en medio no se nota
       mirando el total: hay que buscarlo. */
    $hay = [];
    foreach (bd()->query("SELECT periodo FROM dof_publicaciones")->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $hay[$p] = true;
    }
    $faltan = [];
    [$y, $m] = array_map('intval', explode('-', $r['a']));
    [$y2, $m2] = array_map('intval', explode('-', $r['b']));
    while ($y < $y2 || ($y === $y2 && $m <= $m2)) {
        $p = sprintf('%04d-%02d', $y, $m);
        if (!isset($hay[$p])) $faltan[] = $p;
        if (++$m > 12) { $m = 1; $y++; }
    }

    return ['hay' => true, 'primero' => $r['a'], 'ultimo' => $r['b'],
            'periodos' => (int)$r['n'], 'monedas' => $monedas, 'faltan' => $faltan];
}
