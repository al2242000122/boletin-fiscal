<?php
/* ============================================================================
   cobertura.php — qué listas están realmente cargadas y cuáles no.

   Existe por un fallo silencioso, que es el peor que puede tener esta
   herramienta. Medido el 13/08/2026: de las 15 listas del catálogo había 5
   cargadas —las cinco del 69-B— y 10 vacías: las siete del Artículo 69 y las
   tres del 69-B Bis. La consulta por lote seguía contestando «no aparece en
   ninguno de los listados cargados», que es cierto y engaña: quien lo lee
   entiende «no está en el 69, ni en el 69-B, ni en el Bis», y de esas tres
   solo se había mirado una.

   Un negativo solo vale lo que vale su cobertura. Aquí se calcula para poder
   decirla en pantalla, en el CSV y en la constancia.
   ============================================================================ */

require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/fuentes.php';

const COBERTURA_ARTICULOS = [
    'art69'      => 'Artículo 69',
    'art69b'     => 'Artículo 69-B',
    'art69b_bis' => 'Artículo 69-B Bis',
];

/**
 * Devuelve el estado de las 15 listas del catálogo.
 *
 * ['listas' => [clave => ['grupo','etiqueta','filas','fecha_archivo','cargada']],
 *  'faltan' => [claves…], 'articulos_incompletos' => ['art69' => 'Artículo 69', …],
 *  'completa' => bool]
 */
function cobertura(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $filas = [];
    try {
        foreach (bd()->query("SELECT lista, COUNT(*) n FROM estatus WHERE vigente = 1
                              GROUP BY lista")->fetchAll() as $r) {
            $filas[$r['lista']] = (int)$r['n'];
        }
    } catch (Throwable $e) { /* sin base, todo cuenta como no cargado */ }

    $fechas = [];
    try {
        foreach (bd()->query("
            SELECT s.lista, s.fecha_archivo, s.fecha_servidor, s.descargado_en
            FROM snapshots s
            JOIN (SELECT lista, MAX(id) ultimo FROM snapshots
                  WHERE procesado_en IS NOT NULL GROUP BY lista) u ON u.ultimo = s.id
        ")->fetchAll() as $s) { $fechas[$s['lista']] = $s; }
    } catch (Throwable $e) { /* idem */ }

    $listas = []; $faltan = []; $porArticulo = [];
    foreach (FUENTES_CATALOGO as $clave => [$grupo, $archivo, $etiqueta]) {
        $cargada = ($filas[$clave] ?? 0) > 0;
        $listas[$clave] = [
            'grupo'          => $grupo,
            'etiqueta'       => $etiqueta,
            'archivo'        => $archivo,
            'filas'          => $filas[$clave] ?? 0,
            'cargada'        => $cargada,
            'fecha_archivo'  => $fechas[$clave]['fecha_archivo']  ?? null,
            'fecha_servidor' => $fechas[$clave]['fecha_servidor'] ?? null,
            'descargado_en'  => $fechas[$clave]['descargado_en']  ?? null,
        ];
        if (!$cargada) $faltan[] = $clave;
        $porArticulo[$grupo][$cargada ? 'si' : 'no'][] = $clave;
    }

    // Un artículo cuenta como incompleto si le falta cualquiera de sus listas.
    $incompletos = [];
    foreach (COBERTURA_ARTICULOS as $grupo => $nombre) {
        if (!empty($porArticulo[$grupo]['no'])) $incompletos[$grupo] = $nombre;
    }

    return $cache = [
        'listas'                => $listas,
        'faltan'                => $faltan,
        'por_articulo'          => $porArticulo,
        'articulos_incompletos' => $incompletos,
        'completa'              => $faltan === [],
    ];
}

/** "Artículo 69 y Artículo 69-B Bis" */
function cobertura_articulos_texto(array $nombres): string
{
    $n = array_values($nombres);
    if (count($n) <= 1) return $n[0] ?? '';
    $ultimo = array_pop($n);
    return implode(', ', $n) . ' y ' . $ultimo;
}

/** Los artículos que sí se consultaron por completo. */
function cobertura_articulos_cubiertos(): array
{
    $c = cobertura();
    $cubiertos = [];
    foreach (COBERTURA_ARTICULOS as $grupo => $nombre) {
        if (!empty($c['por_articulo'][$grupo]['si']) && empty($c['por_articulo'][$grupo]['no'])) {
            $cubiertos[$grupo] = $nombre;
        }
    }
    return $cubiertos;
}
