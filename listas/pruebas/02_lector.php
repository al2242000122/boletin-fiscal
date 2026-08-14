<?php
/* ============================================================================
   02_lector.php — la lectura de los CSV del SAT.

   Los casos de casos/ están escritos en windows-1252 y con CRLF, igual que los
   archivos reales: si alguien "arregla" el filtro de conversión, esto lo caza.
   ============================================================================ */

require_once __DIR__ . '/../cron/lib/csv_sat.php';

/** Lee un caso entero y devuelve las filas ya normalizadas. */
function leer_caso(string $archivo, string $familia): array
{
    $h = csv_sat_abrir(caso($archivo));
    if (!$h) return [];
    $cab = csv_sat_encabezado($h);
    $filas = [];
    foreach (csv_sat_filas($h, $familia, $cab['columnas']) as $f) $filas[] = $f;
    fclose($h);
    return ['cab' => $cab, 'filas' => $filas];
}

/* --- registro partido en dos líneas ------------------------------------
   El salto de línea está DENTRO del campo entrecomillado. fgetcsv lo lee bien;
   un lector línea a línea parte el registro y corrompe dos filas. Es la fila 2
   real de Listado_69_B_Bis_Completo.csv. */
$r = leer_caso('bis_fila_partida.csv', 'art69b_bis');
comprobar('el registro partido no se cuenta dos veces', 3, count($r['filas']));

$partida = null;
foreach ($r['filas'] as $f) if ($f['rfc'] === 'CPH061010RB7') $partida = $f;
comprobar_que('el registro partido se lee entero', $partida !== null
    && str_contains($partida['nombre'], 'CONSTRUCTORA DE PROYECTOS')
    && str_contains($partida['nombre'], 'S.A. DE C.V.'),
    'nombre leído: ' . var_export($partida['nombre'] ?? null, true));
comprobar('y conserva su situación', 'Definitivo', $partida['situacion'] ?? '');

/* --- encabezado bajo el preámbulo --------------------------------------
   El 69-B trae dos líneas antes del encabezado y el Art. 69 ninguna. No se
   cuentan a ciegas: se busca la fila que tiene una celda "RFC". */
comprobar('en 69-B Bis encuentra las 12 columnas', 12, count($r['cab']['columnas']));

$a69 = leer_caso('art69_muestra.csv', 'art69');
comprobar('en Art. 69 el encabezado está en la primera línea', 6, count($a69['cab']['columnas']));
comprobar('Art. 69 no declara fecha en el archivo', null, $a69['cab']['fecha_archivo']);
comprobar('lee las tres filas del Art. 69', 3, count($a69['filas']));
comprobar('toma el supuesto',    'FIRMES',           $a69['filas'][0]['supuesto']);
comprobar('toma la entidad',     'CIUDAD DE MEXICO', $a69['filas'][0]['entidad']);
comprobar('respeta el TIPO PERSONA del archivo', 'F', $a69['filas'][1]['tipo_persona']);
// Medido en la muestra de Firmes.csv: hay una fila sin SUPUESTO y sin TIPO
// PERSONA. No debe tumbar la lectura ni inventar valores.
comprobar('la fila sin supuesto se lee igual', '', $a69['filas'][2]['supuesto']);

/* --- Ñ, suprimidos y RFC rotos en un mismo archivo --------------------- */
$m = leer_caso('enes_y_suprimidos.csv', 'art69b');
$por = ['validos' => 0, 'suprimidos' => 0, 'invalidos' => 0];
foreach ($m['filas'] as $f) {
    if ($f['rfc_motivo'] === 'suprimido') $por['suprimidos']++;
    elseif ($f['rfc_valido'])             $por['validos']++;
    else                                  $por['invalidos']++;
}
comprobar('cuenta los válidos',    4, $por['validos']);      // 2 con Ñ + 1 física + 1 con guiones
comprobar('cuenta los suprimidos', 1, $por['suprimidos']);
comprobar('cuenta los inválidos',  2, $por['invalidos']);    // corto y vacío

comprobar('la Ñ sobrevive a la conversión de windows-1252',
          'ÑAÑ140114GY4', $m['filas'][0]['rfc']);
comprobar_que('los acentos del nombre también',
          str_contains($m['filas'][1]['nombre'], 'ÑOVEÑA'),
          'nombre leído: ' . var_export($m['filas'][1]['nombre'], true));
comprobar('el RFC con guiones se normaliza al leer', 'AAA080808HL8', $m['filas'][6]['rfc']);

/* --- un byte inválido no puede cortar la lectura -----------------------
   El caso real que lo destapó: CSDsinefectos.csv trae un byte 0x8D —posición
   sin asignar en windows-1252— dentro de un nombre, al 6% del archivo. Con el
   filtro de iconv que se usaba antes, la lectura se paraba ahí: 3 542 filas de
   60 001, y las otras 56 459 desaparecían sin una sola advertencia. Un
   contribuyente con el certificado sin efectos contestaba «no aparece». */
$b = leer_caso('byte_invalido.csv', 'art69');
comprobar('un byte inválido no corta la lectura', 5, count($b['filas']));
comprobar_que('las filas posteriores al byte malo siguen ahí',
    ($b['filas'][4]['rfc'] ?? '') === 'ÑAÑ050505EEE',
    'última fila leída: ' . var_export($b['filas'][count($b['filas'])-1]['rfc'] ?? null, true));
comprobar_que('la fila del byte malo se lee, con el nombre saneado',
    str_contains($b['filas'][1]['nombre'] ?? '', 'DIESS DESARROLLO'),
    'nombre leído: ' . var_export($b['filas'][1]['nombre'] ?? null, true));

/* --- fecha del preámbulo ----------------------------------------------
   Es la fecha que vale para las constancias. El Last-Modified del servidor
   puede ir semanas por delante: medido, 17 días. */
comprobar('lee la fecha del preámbulo', '2026-05-31', $m['cab']['fecha_archivo']);
