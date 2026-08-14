<?php
/* ============================================================================
   csv_sat.php — lectura de los CSV del SAT.

   Cada decisión de aquí sale de haber inspeccionado los archivos reales el
   12/08/2026; está documentado en docs/ESQUEMAS.md. Resumen de lo que hay
   que respetar:

   · Codificación windows-1252, no UTF-8. Se convierte con un filtro de flujo
     para no cargar 20 MB en memoria.
   · Art. 69-B y 69-B Bis traen DOS líneas de preámbulo antes del encabezado.
     Art. 69 no trae ninguna. No se cuenta a ciegas: se busca la fila que
     tiene RFC.
   · Hay registros partidos en dos líneas por un salto dentro de un campo
     entrecomillado. fgetcsv los lee bien; leer por líneas los corrompe.
   · Presuntos.csv declara 23 columnas y el listado completo 20. La primera
     columna se llama "No." en uno y "No" en otro.
   · Hay RFC vacíos, de 6 y de 16 caracteres. No son excepciones raras: están
     en el archivo hoy.
   ============================================================================ */

const CSV_SAT_MAX_PREAMBULO = 10;   // margen: hoy son 2, no confiamos en que siga

/* ------------------------------------------------------------------ apertura */

/**
 * Convierte windows-1252 a UTF-8 mientras se lee, sin abortar nunca.
 *
 * Aquí estaba el peor fallo que ha tenido esta herramienta. Antes se usaba
 * `convert.iconv.windows-1252/utf-8//TRANSLIT` con un comentario que afirmaba
 * que //TRANSLIT impedía que un byte raro tumbara la conversión. Es falso, y
 * está medido el 13/08/2026: el archivo CSDsinefectos.csv trae **un** byte 0x8D
 * —posición sin asignar en windows-1252— dentro de un nombre, al 6% del
 * archivo. iconv se detiene ahí. El resultado no era un error: eran 3 542 filas
 * leídas de 60 001, y las otras 56 459 desaparecían sin una sola advertencia.
 * Un contribuyente con el certificado sin efectos contestaba «no aparece».
 *
 * mb_convert_encoding sustituye lo que no reconoce en lugar de abortar. Se usa
 * un filtro propio para no perder la lectura en flujo: los archivos grandes son
 * de 20 MB y no caben cómodamente en memoria.
 *
 * Convertir trozo a trozo es seguro porque windows-1252 gasta exactamente un
 * byte por carácter: ninguno puede quedar partido entre dos trozos. Con una
 * codificación multibyte de origen esto no valdría.
 */
class CsvSatFiltro1252 extends php_user_filter
{
    public function filter($entrada, $salida, &$consumido, $cerrando): int
    {
        while ($cubo = stream_bucket_make_writeable($entrada)) {
            $largoEntrada  = $cubo->datalen;   // antes de convertir: la conversión alarga
            $cubo->data    = mb_convert_encoding($cubo->data, 'UTF-8', 'Windows-1252');
            $cubo->datalen = strlen($cubo->data);
            $consumido    += $largoEntrada;
            stream_bucket_append($salida, $cubo);
        }
        return PSFS_PASS_ON;
    }
}
stream_filter_register('sat.1252', 'CsvSatFiltro1252');

/**
 * Abre un CSV del SAT convirtiendo windows-1252 a UTF-8 al vuelo.
 * Devuelve un recurso listo para fgetcsv, o null si no se pudo abrir.
 */
function csv_sat_abrir(string $ruta)
{
    $h = @fopen($ruta, 'r');
    if (!$h) return null;
    @stream_filter_append($h, 'sat.1252', STREAM_FILTER_READ);
    return $h;
}

/**
 * Lee el preámbulo y el encabezado.
 * Devuelve ['preambulo' => [...], 'columnas' => [...], 'fecha_archivo' => 'Y-m-d'|null]
 * El recurso queda posicionado en la primera fila de datos.
 */
function csv_sat_encabezado($h): array
{
    $preambulo = [];
    $columnas  = [];

    for ($i = 0; $i < CSV_SAT_MAX_PREAMBULO; $i++) {
        $pos = ftell($h);
        $fila = fgetcsv($h, 0, ',', '"', '\\');
        if ($fila === false) break;

        // El encabezado es la fila que contiene una celda exactamente "RFC".
        $esEncabezado = false;
        foreach ($fila as $c) {
            if (strcasecmp(trim((string)$c), 'RFC') === 0) { $esEncabezado = true; break; }
        }
        if ($esEncabezado) {
            $columnas = array_map(fn($c) => trim((string)$c), $fila);
            break;
        }
        $preambulo[] = implode(' ', array_filter(array_map('trim', $fila), 'strlen'));
    }

    return [
        'preambulo'     => $preambulo,
        'columnas'      => $columnas,
        'fecha_archivo' => csv_sat_fecha_preambulo($preambulo),
    ];
}

/* --------------------------------------------------------------------- fechas */

const CSV_SAT_MESES = [
    'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
    'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
    'noviembre' => 11, 'diciembre' => 12,
];

/**
 * "Información actualizada al 31 de mayo de 2026" -> "2026-05-31".
 *
 * Esta es la fecha que vale. El Last-Modified del servidor puede ir semanas
 * por delante: medido, 17 días de diferencia. Una constancia con la fecha
 * equivocada no sirve de evidencia.
 */
function csv_sat_fecha_preambulo(array $preambulo): ?string
{
    $texto = mb_strtolower(implode(' ', $preambulo), 'UTF-8');
    if (preg_match('/actualizada\s+al\s+(\d{1,2})\s+de\s+([a-záéíóú]+)\s+de\s+(\d{4})/u', $texto, $m)) {
        $mes = CSV_SAT_MESES[strtr($m[2], ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u'])] ?? null;
        if ($mes) return sprintf('%04d-%02d-%02d', (int)$m[3], $mes, (int)$m[1]);
    }
    return null;
}

/** "01/06/2018" -> "2018-06-01". Devuelve null si no cuadra. */
function csv_sat_fecha(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
        $d = (int)$m[1]; $me = (int)$m[2]; $a = (int)$m[3];
        if (checkdate($me, $d, $a)) return sprintf('%04d-%02d-%02d', $a, $me, $d);
    }
    return null;
}

/* ----------------------------------------------------------------------- RFC */

/**
 * Normaliza y clasifica un RFC.
 * Devuelve ['rfc' => string, 'valido' => bool, 'tipo' => 'M'|'F'|null, 'motivo' => string]
 *
 * 12 caracteres = persona moral, 13 = persona física. Esa distinción no es
 * cosmética: las personas físicas son datos personales bajo la LFPDPPP y las
 * morales no.
 */
function csv_sat_rfc(?string $v): array
{
    $r = mb_strtoupper(preg_replace('/[\s\-\.]/u', '', (string)$v), 'UTF-8');
    // mb_strlen, NO strlen: la Ñ ocupa dos bytes en UTF-8, así que un RFC como
    // ÑAÑ140114GY4 —12 caracteres, perfectamente válido— se contaba como de 14
    // y se descartaba. En el listado de 69-B hay 98 RFC con Ñ.
    $n = mb_strlen($r, 'UTF-8');

    if ($r === '')  return ['rfc' => '', 'valido' => false, 'tipo' => null, 'motivo' => 'vacio'];

    // El SAT tacha algunos registros: "Información suprimida en cumplimiento de
    // la Ley Federal de Protección de Datos Personales". No son basura ni error
    // de captura: son filas reales con el dato retirado a propósito, y hay que
    // conservarlas como tales.
    if (preg_match('/^X{10,}$/', $r))
        return ['rfc' => $r, 'valido' => false, 'tipo' => null, 'motivo' => 'suprimido'];

    if ($n !== 12 && $n !== 13)
        return ['rfc' => $r, 'valido' => false, 'tipo' => null, 'motivo' => "longitud_$n"];
    // 12: AAA000000AAA · 13: AAAA000000AAA
    $patron = $n === 13 ? '/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/u' : '/^[A-ZÑ&]{3}\d{6}[A-Z0-9]{3}$/u';
    if (!preg_match($patron, $r))
        return ['rfc' => $r, 'valido' => false, 'tipo' => null, 'motivo' => 'formato'];

    return ['rfc' => $r, 'valido' => true, 'tipo' => $n === 12 ? 'M' : 'F', 'motivo' => ''];
}

/* ------------------------------------------------------------------- lectura */

/**
 * Recorre las filas de datos y las entrega ya normalizadas.
 * Es un generador: no carga el archivo en memoria.
 *
 * $familia: 'art69' | 'art69b' | 'art69b_bis'
 */
function csv_sat_filas($h, string $familia, array $columnas): Generator
{
    /* Los siete archivos del Artículo 69 NO comparten encabezado, aunque la
       documentación del SAT los presente como si sí. Medido el 13/08/2026:

         firmes · no_localizados · exigibles   RFC, RAZON SOCIAL, TIPO PERSONA,
                                               SUPUESTO, FECHA…, ENTIDAD FEDERATIVA
         sentencias                            igual, pero «RAZÓN SOCIAL» con tilde
         cancelados                            8 columnas: añade FECHA DE
                                               CANCELACION y MONTO
         entes_publicos                        sin TIPO PERSONA; añade EJERCICIO
                                               y PERIODO
         csd_sin_efectos                       «NOMBRE O RAZON SOCIAL» y
                                               «SUPUESTO DE CANCELACION CSD»;
                                               ni TIPO PERSONA ni ENTIDAD

       Con la comparación exacta que había antes, «RAZÓN SOCIAL» no casaba con
       «RAZON SOCIAL» y «NOMBRE O RAZON SOCIAL» tampoco: 572 filas de Sentencias
       y 3 520 de CSD se guardaban sin nombre, y las de CSD además sin supuesto.
       Se comparan sin acentos y admitiendo variantes. */
    $normal = function (string $s): string {
        return strtr(mb_strtoupper(trim($s), 'UTF-8'),
                     ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U']);
    };

    $idx = [];
    foreach ($columnas as $i => $c) $idx[$normal((string)$c)] = $i;

    $col = function (array $fila, array $nombres) use ($idx, $normal) {
        foreach ($nombres as $n) {
            $k = $normal($n);
            if (isset($idx[$k]) && isset($fila[$idx[$k]])) return trim((string)$fila[$idx[$k]]);
        }
        return '';
    };

    /* Red de seguridad para el nombre, que es lo que hace legible un hallazgo:
       si ninguna variante conocida casa, vale cualquier columna cuyo título
       hable de nombre o razón social. Solo se aplica al nombre: en los demás
       campos un acierto por aproximación sería peor que un hueco. */
    $colNombre = function (array $fila) use ($idx, $col) {
        $v = $col($fila, ['Nombre del Contribuyente', 'RAZON SOCIAL', 'NOMBRE O RAZON SOCIAL']);
        if ($v !== '') return $v;
        foreach ($idx as $titulo => $i) {
            if ((str_contains($titulo, 'RAZON SOCIAL') || str_contains($titulo, 'NOMBRE'))
                && isset($fila[$i])) return trim((string)$fila[$i]);
        }
        return '';
    };

    $n = 0;
    while (($fila = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
        $n++;
        // filas totalmente vacías: el final de estos archivos suele traerlas
        if (count(array_filter($fila, fn($c) => trim((string)$c) !== '')) === 0) continue;

        $rfc = csv_sat_rfc($col($fila, ['RFC']));

        $reg = [
            'linea'        => $n,
            'rfc'          => $rfc['rfc'],
            'rfc_valido'   => $rfc['valido'],
            'rfc_motivo'   => $rfc['motivo'],
            'tipo_persona' => $rfc['tipo'],
            'nombre'       => $colNombre($fila),
            'situacion'    => null,
            'supuesto'     => null,
            'entidad'      => null,
            'fecha_primera_publicacion' => null,
            'crudo'        => $fila,
        ];

        if ($familia === 'art69') {
            $reg['supuesto'] = $col($fila, ['SUPUESTO', 'SUPUESTO DE CANCELACION CSD']);
            $reg['entidad']  = $col($fila, ['ENTIDAD FEDERATIVA']);
            $reg['fecha_primera_publicacion'] = csv_sat_fecha($col($fila,
                ['FECHA DE PRIMERA PUBLICACION', 'FECHAS DE PRIMERA PUBLICACION', 'FECHA DE PUBLICACION']));
            // el archivo trae su propio TIPO PERSONA; si está, manda sobre la longitud
            $tp = strtoupper($col($fila, ['TIPO PERSONA']));
            if ($tp === 'M' || $tp === 'F') $reg['tipo_persona'] = $tp;
        } else {
            $reg['situacion'] = $col($fila, ['Situación del contribuyente', 'Situacion del contribuyente']);
        }

        yield $reg;
    }
}
