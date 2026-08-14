<?php
/* ============================================================================
   dof_tc.php — tipo de cambio del DOF (indicador 158).

   El dato que el artículo 20 del CFF manda usar para convertir moneda
   extranjera, publicado en el DOF cada día hábil bancario.

   Sirve además de LATIDO. Las listas del SAT se publican cada uno o dos meses,
   así que si el cron se muere nadie se entera hasta que ya es tarde. Esto se
   publica todos los días hábiles: si el último dato tiene más de unos días, el
   cron está muerto y se sabe el mismo día.

   Tres cosas medidas el 14/08/2026 que hay que respetar:

   · HAY QUE USAR dof.gob.mx SIN www. El certificado TLS del DOF solo cubre
     dof.gob.mx; con www falla la verificación de nombre y la descarga muere.
     No es cosa de un servidor concreto: el certificado, emitido el 15/07/2026,
     trae un único nombre en su lista.

   · LA FECHA ES LA DE PUBLICACIÓN, NO LA DE DETERMINACIÓN. La fila con fecha D
     trae el tipo que Banxico determinó el día hábil anterior. Está explicado a
     fondo en esquema.sql, encima de la tabla. No restar un día.

   · EL DOF CONTESTA 200 CON PÁGINA VACÍA ante cualquier parámetro inválido.
     Medido: la ventana 15/08–16/08 —un fin de semana— devuelve 200, 42,603
     bytes y cero filas; exactamente igual que una avería. Distinguirlos solo
     se puede por la antigüedad del último dato, no por la respuesta.
   ============================================================================ */

require_once __DIR__ . '/bd.php';

const DOF_TC_URL = 'https://dof.gob.mx/indicadores_detalle.php?cod_tipo_indicador=158'
                 . '&dfecha={desde}&hfecha={hasta}';
const DOF_TC_UA  = 'Mozilla/5.0 (compatible; ISS-DOF/1.0; +https://insusermx.com)';

/* Rango de cordura en pesos por dólar. Solo para descartar basura si el DOF
   sirviera otra cosa en esa tabla —el indicador se llama «TIPO DE CAMBIO Y
   TASAS»—. Lo descartado NO se calla: se cuenta y se reporta. Medido sobre la
   serie completa desde 1991, este filtro tira 2,647 de 8,723 filas: son los
   pesos anteriores a 2001, no basura. De 2021 en adelante no tira ninguna. */
const DOF_TC_MIN = 10.0;
const DOF_TC_MAX = 40.0;

/* Días hábiles sin dato nuevo a partir de los cuales se considera averiado.
   Sale de la serie: el hueco más largo medido entre publicaciones consecutivas
   es de 5 días naturales (Semana Santa y algún puente), así que por debajo de
   ese umbral habría falsas alarmas cada primavera. */
const DOF_TC_DIAS_ALARMA = 6;

/* ------------------------------------------------------------------ descarga */

/**
 * Pide el indicador para un rango y devuelve las filas ya interpretadas.
 * ['filas' => [['fecha'=>'Y-m-d','valor'=>'17.053000'], …],
 *  'fuera_rango' => n, 'bytes' => n, 'codigo' => n, 'error' => '']
 */
function dof_tc_descargar(string $desde, string $hasta): array
{
    $url = strtr(DOF_TC_URL, ['{desde}' => $desde, '{hasta}' => $hasta]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT      => DOF_TC_UA,
        CURLOPT_ENCODING       => '',
    ]);
    $html   = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($html === false || $codigo < 200 || $codigo >= 300) {
        return ['filas' => [], 'fuera_rango' => 0, 'bytes' => 0,
                'codigo' => $codigo, 'error' => $error ?: "HTTP $codigo"];
    }

    $r = dof_tc_interpretar($html);
    $r['codigo'] = $codigo;
    $r['error']  = '';
    return $r;
}

/**
 * Saca las filas del HTML del indicador. Separado de la descarga a propósito:
 * así se puede probar contra un archivo guardado, sin depender de que el DOF
 * esté en pie.
 *
 * Se quitan las etiquetas y se busca 'dd-mm-aaaa   17.053000'. Medido sobre la
 * serie completa desde 1991: captura 8,723 de 8,723 filas reales, sin falsos
 * positivos, sin duplicados y sin paginación — el DOF entrega 1.72 MB de un
 * tirón en 1.3 s.
 */
function dof_tc_interpretar(string $html): array
{
    $plano = preg_replace('/<[^>]+>/', ' ', $html);
    // \d{1,4}, no \d{1,2}: con dos digitos, un valor de tres cifras —o basura
    // como 1234.5678— no se descartaba, simplemente NO SE VEIA, y el contador
    // de descartadas decia cero. Prefiero que entre y que el rango de cordura
    // lo tire contandolo: lo que se descarta se cuenta, lo que no se ve no.
    preg_match_all('/(\d{2})[-\/](\d{2})[-\/](\d{4})\s+(\d{1,4}\.\d{4,6})/', $plano, $m, PREG_SET_ORDER);

    $filas = []; $fuera = 0;
    foreach ($m as $f) {
        [$d, $me, $a, $v] = [(int)$f[1], (int)$f[2], (int)$f[3], $f[4]];
        if (!checkdate($me, $d, $a)) continue;
        if ((float)$v < DOF_TC_MIN || (float)$v > DOF_TC_MAX) { $fuera++; continue; }
        // El valor se queda como cadena, no como float: es una cifra fiscal y
        // el redondeo lo tiene que decidir la columna DECIMAL, no PHP.
        $filas[sprintf('%04d-%02d-%02d', $a, $me, $d)] = $v;
    }

    $salida = [];
    foreach ($filas as $fecha => $valor) $salida[] = ['fecha' => $fecha, 'valor' => $valor];
    usort($salida, fn($x, $y) => $x['fecha'] <=> $y['fecha']);

    return ['filas' => $salida, 'fuera_rango' => $fuera, 'bytes' => strlen($html),
            'codigo' => 200, 'error' => ''];
}

/* -------------------------------------------------------------------- guardar */

/** Inserta lo que no estaba. Devuelve cuántas filas son nuevas. */
function dof_tc_guardar(array $filas): int
{
    if (!$filas) return 0;
    $nuevas = 0;
    $st = bd()->prepare("INSERT INTO dof_tipo_cambio (fecha, valor, fuente, ingresado_en)
                         VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    $ahora = date('Y-m-d H:i:s');
    foreach ($filas as $f) {
        $st->execute([$f['fecha'], $f['valor'], 'DOF_INDICADOR', $ahora]);
        // rowCount(): 1 = insertada, 2 = actualizada, 0 = igual que estaba.
        if ($st->rowCount() === 1) $nuevas++;
    }
    return $nuevas;
}

/* ---------------------------------------------------------------- sincronizar */

/**
 * Trae los últimos días y deja constancia de la corrida.
 * $dias: ventana hacia atrás. 15 cubre de sobra un puente largo.
 */
function dof_tc_sincronizar(int $dias = 15, ?callable $log = null): array
{
    $log ??= function ($t) {};

    $hasta = date('d/m/Y');
    $desde = date('d/m/Y', strtotime("-$dias days"));
    $log("   DOF tipo de cambio: pidiendo $desde a $hasta");

    $r = dof_tc_descargar($desde, $hasta);

    if ($r['error'] !== '') {
        dof_tc_registrar_corrida($desde, $hasta, 0, 0, 0, null, false, $r['error']);
        $log('   FALLO: ' . $r['error']);
        return ['ok' => false, 'nuevas' => 0, 'motivo' => $r['error']];
    }

    $nuevas = dof_tc_guardar($r['filas']);
    $ultima = $r['filas'] ? end($r['filas'])['fecha'] : null;

    /* Cero filas no es necesariamente un fallo: en un puente largo la ventana
       puede no traer nada nuevo. Pero cero filas EN TODA la ventana sí lo es,
       porque 15 días naturales siempre contienen días hábiles. */
    $sospechoso = !$r['filas'];
    $detalle = sprintf('%d filas, %d nuevas, %d fuera de rango, %s bytes',
                       count($r['filas']), $nuevas, $r['fuera_rango'], number_format($r['bytes']));
    if ($sospechoso) {
        $detalle = 'ventana de ' . $dias . ' días sin una sola fila — ' . $detalle;
    }

    dof_tc_registrar_corrida($desde, $hasta, count($r['filas']), $nuevas,
                             $r['fuera_rango'], $ultima, !$sospechoso, $detalle);

    $log(sprintf('   %d filas · %d nuevas%s%s', count($r['filas']), $nuevas,
        $r['fuera_rango'] ? ' · ' . $r['fuera_rango'] . ' fuera del rango de cordura' : '',
        $ultima ? ' · última publicación ' . $ultima : ''));
    if ($sospechoso) {
        $log('   OJO: el DOF respondió 200 pero sin ninguna fila. Puede haber cambiado el maquetado.');
    }

    return ['ok' => !$sospechoso, 'nuevas' => $nuevas, 'ultima' => $ultima, 'motivo' => ''];
}

function dof_tc_registrar_corrida(string $desde, string $hasta, int $leidas, int $nuevas,
                                  int $fuera, ?string $ultima, bool $ok, string $detalle): void
{
    try {
        $iso = fn($d) => ($t = strtotime(str_replace('/', '-', $d))) ? date('Y-m-d', $t) : null;
        bd()->prepare("INSERT INTO dof_corridas
              (corrida_en, desde, hasta, filas_leidas, filas_nuevas, filas_fuera_rango,
               ultima_fecha, ok, detalle)
              VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([date('Y-m-d H:i:s'), $iso($desde), $iso($hasta), $leidas, $nuevas,
                       $fuera, $ultima, $ok ? 1 : 0, mb_substr($detalle, 0, 300)]);
    } catch (Throwable $e) { /* la constancia no puede tumbar la sincronización */ }
}

/* ------------------------------------------------------------------ consulta */

/** El tipo publicado exactamente ese día, o null si ese día no hubo. */
function dof_tc_publicado(string $fecha): ?string
{
    $st = bd()->prepare("SELECT valor FROM dof_tipo_cambio WHERE fecha = ?");
    $st->execute([$fecha]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/**
 * El que toca aplicar a una operación de esa fecha.
 *
 * Artículo 20 del CFF: se usa el publicado en el DOF el día hábil bancario
 * inmediato ANTERIOR. Por eso es «< fecha», estrictamente menor, y por eso se
 * busca «la última publicación anterior» y no «el día anterior»: no hay filas
 * en sábados, domingos ni feriados, así que restar un día natural devolvería
 * vacío en una fracción grande de los casos.
 *
 * Cambiar el «<» por «<=» aplicaría el publicado el mismo día de la operación.
 * Es un error de un carácter, no truena nada, y mueve todos los importes.
 */
function dof_tc_fiscal(string $fechaOperacion): ?array
{
    $st = bd()->prepare("SELECT fecha, valor FROM dof_tipo_cambio
                         WHERE fecha < ? ORDER BY fecha DESC LIMIT 1");
    $st->execute([$fechaOperacion]);
    $r = $st->fetch();
    if (!$r) return null;   // antes del arranque de la serie: se dice, no se inventa un cero
    return ['fecha_operacion' => $fechaOperacion,
            'fecha_publicacion' => $r['fecha'], 'valor' => (string)$r['valor']];
}

/* -------------------------------------------------------------------- estado */

/**
 * Cómo está el latido.
 * ['hay' => bool, 'ultima' => 'Y-m-d'|null, 'valor' => string|null,
 *  'dias' => int|null, 'vivo' => bool, 'corrida' => fila|null, 'total' => int]
 */
function dof_tc_estado(): array
{
    try {
        $r = bd()->query("SELECT fecha, valor FROM dof_tipo_cambio
                          ORDER BY fecha DESC LIMIT 1")->fetch();
        $total = (int)bd()->query("SELECT COUNT(*) FROM dof_tipo_cambio")->fetchColumn();
        $corrida = bd()->query("SELECT * FROM dof_corridas ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    } catch (Throwable $e) {
        return ['hay' => false, 'ultima' => null, 'valor' => null, 'dias' => null,
                'vivo' => false, 'corrida' => null, 'total' => 0];
    }

    if (!$r) {
        return ['hay' => false, 'ultima' => null, 'valor' => null, 'dias' => null,
                'vivo' => false, 'corrida' => $corrida, 'total' => 0];
    }

    $dias = (int)floor((strtotime(date('Y-m-d')) - strtotime($r['fecha'])) / 86400);
    return ['hay' => true, 'ultima' => $r['fecha'], 'valor' => (string)$r['valor'],
            'dias' => $dias, 'vivo' => $dias <= DOF_TC_DIAS_ALARMA,
            'corrida' => $corrida, 'total' => $total];
}
