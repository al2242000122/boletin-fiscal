<?php
/* ============================================================================
   06_dof_eq.php — equivalencias mensuales de monedas.

   Lo que se fija aquí, sobre todo, es que la marca de «por mil unidades» se
   deduzca del PIE de cada publicación y no del número de la llamada. Es donde
   una equivocación multiplica un importe por mil.
   ============================================================================ */

require_once __DIR__ . '/../cron/lib/dof_eq.php';

/* --- el periodo se lee del título o del cuerpo ------------------------- */
comprobar('lee el mes del título', '2026-07',
    dof_eq_periodo('…correspondiente al mes de julio de 2026'));
comprobar('con abreviatura',       '2021-02', dof_eq_periodo('feb-2021'));
comprobar('sin mes reconocible',   null,      dof_eq_periodo('un texto cualquiera'));

/* --- país, moneda y llamada -------------------------------------------- */
$p = dof_eq_partir('Vietnam   Dong 3/');
comprobar('separa el país',    'Vietnam', $p['pais']);
comprobar('y la moneda',       'Dong',    $p['moneda']);
comprobar('y la llamada',      '3/',      $p['nota']);

$p = dof_eq_partir('China   Yuan extracontinental 2/');
comprobar('monedas con dos palabras', 'Yuan extracontinental', $p['moneda']);

$p = dof_eq_partir('Arabia Saudita   Riyal');
comprobar('países con dos palabras',  'Arabia Saudita', $p['pais']);
comprobar('y sin llamada queda null', null, $p['nota']);

comprobar('una sola columna no es una fila', null, dof_eq_partir('Solo texto'));

/* --- el pie manda, no el número de la llamada --------------------------
   Medido el 14/08/2026: en 2021 la llamada de «por mil» era «2/»; en 2026 es
   «3/», porque al añadirse el yuan extracontinental las llamadas rotaron. Un
   código que compare contra un número fijo se equivoca sin enterarse. */
$pie2026 = ['2/' => 'Corresponde al tipo de cambio cuya cotización es realizada fuera de China continental.',
            '3/' => 'El tipo de cambio está expresado en dólares por mil unidades domésticas.'];
comprobar('en 2026 la llamada de por mil es la 3', '3/', dof_eq_llamada_por_mil($pie2026));

$pie2021 = ['2/' => 'El tipo de cambio esta expresado en dolares por mil unidades domesticas.'];
comprobar('en 2021 era la 2',                      '2/', dof_eq_llamada_por_mil($pie2021));

comprobar('si el pie no la menciona, no se inventa', null,
    dof_eq_llamada_por_mil(['1/' => 'Moneda Equivalencia de la unidad.']));

/* --- la nota entera ---------------------------------------------------- */
$html = @file_get_contents(caso('dof_nota_equivalencias_2026_07.html'));
if ($html === false) {
    omitir('lectura de una nota completa', 'falta casos/dof_nota_equivalencias_2026_07.html');
} else {
    $d = dof_eq_interpretar($html);
    comprobar('lee el periodo de la nota',   '2026-07', $d['periodo']);
    comprobar('encuentra las 69 monedas',    69,        count($d['filas']));
    comprobar('y la llamada de por mil',     '3/',      $d['por_mil']);

    $por = [];
    foreach ($d['filas'] as $f) $por["{$f['pais']}|{$f['moneda']}"] = $f;

    comprobar_que('el yuan extracontinental existe',
        isset($por['China|Yuan extracontinental']), implode(' ', array_slice(array_keys($por), 0, 3)));

    /* La trampa: el yuan extracontinental lleva la llamada «2/», que en esta
       publicación NO significa por mil. Marcarlo dividiría por mil un yuan. */
    comprobar('el yuan extracontinental lleva la llamada 2/', '2/',
        $por['China|Yuan extracontinental']['nota']);
    comprobar('pero NO es por mil',                            0,
        $por['China|Yuan extracontinental']['por_mil']);

    comprobar('el dong vietnamita sí es por mil', 1, $por['Vietnam|Dong']['por_mil']);
    comprobar('y conserva el valor tal cual viene', '0.03803', $por['Vietnam|Dong']['valor']);

    $cuantasPorMil = count(array_filter($d['filas'], fn($f) => $f['por_mil'] === 1));
    comprobar('cinco monedas vienen por mil en julio de 2026', 5, $cuantasPorMil);

    // Las dos filas de China conviven: por eso la llave lleva la moneda.
    $china = array_filter($d['filas'], fn($f) => $f['pais'] === 'China');
    comprobar('China trae dos monedas', 2, count($china));
}
