<?php
/* ============================================================================
   aviso.php — el correo que hace que nadie tenga que acordarse de entrar.

   Las pantallas solo sirven si alguien las mira. Avisa de dos cosas distintas,
   en una sola carta por corrida:

   · Publicación nueva. El SAT sacó una versión nueva de cualquiera de las 15
     listas. Es la señal de «vuelve a pasar la cartera de clientes por la
     consulta por lote». Pasa también sin nada urgente detrás: el Artículo 69
     no tiene presuntos, y aun así hay que volver a mirar.

   · Movimiento urgente (prioridad 2): un RFC entra como presunto o pasa a
     definitivo. Ahí corre plazo, así que manda el asunto del correo.

   Nunca se avisa de la carga inicial —es el punto de partida, no noticia— y
   nada se avisa dos veces: queda sellado en eventos.avisado_en y en
   snapshots.avisado_en. Si el envío falla no se sella, así que el siguiente
   intento lo recoge. Un fallo del correo no tumba la ingesta: es preferible
   tener el dato sin aviso que perder la carga.
   ============================================================================ */

require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/migracion.php';

const AVISO_MAX_FILAS = 40;   // en el correo; el resto se cuenta

/** Direcciones configuradas en privado/config.php. */
function aviso_destinatarios(): array
{
    bd_config();   // asegura que config.php está cargado
    $crudo = defined('AVISO_CORREO') ? (string)AVISO_CORREO : '';
    $lista = array_filter(array_map('trim', explode(',', $crudo)),
                          fn($d) => $d !== '' && filter_var($d, FILTER_VALIDATE_EMAIL));
    return array_values($lista);
}

function aviso_remitente(): string
{
    bd_config();
    $r = defined('AVISO_REMITENTE') ? trim((string)AVISO_REMITENTE) : '';
    return filter_var($r, FILTER_VALIDATE_EMAIL) ? $r : '';
}

function aviso_configurado(): bool
{
    return aviso_destinatarios() !== [] && aviso_remitente() !== '';
}

/**
 * Manda un correo con lo urgente que aún no se ha avisado.
 * Devuelve ['enviados' => n, 'eventos' => n, 'motivo' => ''].
 */
function avisar_pendientes(?callable $log = null): array
{
    $log ??= function ($t) {};
    migrar_columnas_pendientes();

    $para = aviso_destinatarios();
    $de   = aviso_remitente();
    if (!$para || !$de) {
        return ['enviados' => 0, 'eventos' => 0,
                'motivo' => 'sin destinatario configurado en privado/config.php'];
    }

    /* Urgente, fuera de la línea base y sin avisar todavía. */
    $ev = bd()->query("
        SELECT e.id, e.rfc, e.nombre, e.tipo, e.lista, e.situacion_anterior, e.situacion_nueva,
               e.tipo_persona, s.fecha_archivo
        FROM eventos e
        JOIN snapshots s ON s.id = e.snapshot_id
        WHERE e.prioridad = 2 AND s.linea_base = 0 AND e.avisado_en IS NULL
        ORDER BY e.tipo, e.rfc")->fetchAll();

    /* Publicaciones nuevas del SAT.
       Esto es lo que de verdad pide el uso interno: no solo «hay un presunto
       nuevo», sino «el SAT sacó una versión nueva de Firmes, vuelve a barrer la
       cartera». Un listado puede republicarse sin que haya movimiento urgente
       —el Artículo 69 no tiene presuntos— y aun así hay que volver a mirar.
       La línea base queda fuera: la primera carga no es una publicación nueva,
       es el punto de partida. */
    $pub = bd()->query("
        SELECT s.id, s.lista, s.fecha_archivo, s.fecha_servidor, s.filas_validas,
               (SELECT COUNT(*) FROM eventos e WHERE e.snapshot_id = s.id AND e.tipo = 'alta')   altas,
               (SELECT COUNT(*) FROM eventos e WHERE e.snapshot_id = s.id AND e.tipo = 'cambio') cambios,
               (SELECT COUNT(*) FROM eventos e WHERE e.snapshot_id = s.id AND e.tipo = 'baja')   bajas
        FROM snapshots s
        WHERE s.linea_base = 0 AND s.procesado_en IS NOT NULL AND s.avisado_en IS NULL
        ORDER BY s.lista")->fetchAll();

    if (!$ev && !$pub) return ['enviados' => 0, 'eventos' => 0, 'motivo' => ''];

    ['asunto' => $asunto, 'cuerpo' => $cuerpo] = aviso_componer($ev, $pub);

    $cab = "MIME-Version: 1.0\r\n"
         . "Content-Type: text/html; charset=UTF-8\r\n"
         . "From: Listas del SAT <$de>\r\n"
         . "Reply-To: $de\r\n"
         . "X-Mailer: insusermx-listas";

    // El quinto parámetro fija el remitente del sobre. Sin él, Hostinger manda
    // el correo a nombre del usuario del sistema y se marca como sospechoso.
    $ok = @mail(implode(', ', $para), aviso_codificar($asunto), $cuerpo, $cab, '-f' . $de);

    if (!$ok) {
        $log('   aviso por correo: no se pudo entregar al servidor de salida');
        return ['enviados' => 0, 'eventos' => count($ev),
                'motivo' => 'mail() devolvió falso'];
    }

    /* Sellar solo si salió: si falló, el próximo intento lo reintenta. */
    $ahora = date('Y-m-d H:i:s');
    if ($ev) {
        $ids = array_column($ev, 'id');
        $huecos = implode(',', array_fill(0, count($ids), '?'));
        bd()->prepare("UPDATE eventos SET avisado_en = ? WHERE id IN ($huecos)")
            ->execute(array_merge([$ahora], $ids));
    }
    if ($pub) {
        $ids = array_column($pub, 'id');
        $huecos = implode(',', array_fill(0, count($ids), '?'));
        bd()->prepare("UPDATE snapshots SET avisado_en = ? WHERE id IN ($huecos)")
            ->execute(array_merge([$ahora], $ids));
    }

    $partes = [];
    if ($pub) $partes[] = count($pub) . ' publicación(es) nueva(s)';
    if ($ev)  $partes[] = count($ev) . ' movimiento(s) urgente(s)';
    $log('   aviso enviado a ' . implode(', ', $para) . ' (' . implode(' y ', $partes) . ')');

    /* Queda constancia de cada envío. Sin esto, «hoy no llegó ningún correo»
       es indistinguible de tres cosas muy distintas: que no hubiera nada que
       avisar, que la ingesta no llegara a correr, o que el correo se perdiera.
       El panel necesita poder decir cuál de las tres. */
    try {
        bd()->prepare("INSERT INTO bitacora (usuario,ip,origen,rfc_consultado,cantidad,resultado,consultado_en)
                       VALUES ('aviso', NULL, 'aviso', NULL, ?, ?, ?)")
            ->execute([count($ev) + count($pub), mb_substr(implode(' y ', $partes), 0, 40), $ahora]);
    } catch (Throwable $e) { /* la constancia no puede tumbar el aviso */ }

    return ['enviados' => count($para), 'eventos' => count($ev),
            'publicaciones' => count($pub), 'motivo' => ''];
}

/** Correo de prueba, para comprobar que el servidor entrega. */
function aviso_probar(): array
{
    $para = aviso_destinatarios();
    $de   = aviso_remitente();
    if (!$para || !$de) return ['ok' => false, 'motivo' => 'falta AVISO_CORREO o AVISO_REMITENTE en privado/config.php'];

    $cuerpo = aviso_plantilla(
        'Prueba de aviso',
        '<p style="margin:0 0 14px">Este correo confirma que el servidor entrega
          los avisos. No hay ningún movimiento del SAT detrás: es una prueba
          lanzada a mano desde el panel.</p>
         <p style="margin:0 0 14px">Llegará un correo como este cuando el SAT
          publique una versión nueva de cualquiera de los listados —la señal de
          que hay que volver a pasar la cartera— y cuando un RFC entre como
          presunto o pase a definitivo en el 69-B, que es lo que tiene plazo.</p>
         <p style="margin:0"><a href="https://insusermx.com/listas/lote.php"
          style="color:#1D6FA5">Consulta por lote</a></p>');

    $cab = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
         . "From: Listas del SAT <$de>\r\nReply-To: $de";

    $ok = @mail(implode(', ', $para), aviso_codificar('Prueba de aviso · Listas del SAT'),
                $cuerpo, $cab, '-f' . $de);

    return $ok ? ['ok' => true,  'motivo' => 'enviado a ' . implode(', ', $para)]
               : ['ok' => false, 'motivo' => 'el servidor no aceptó el correo (mail() devolvió falso)'];
}

/* ------------------------------------------------------------------ formato */

/** Asunto y cuerpo, sin enviar nada. Separado para poder revisarlo. */
function aviso_componer(array $ev, array $pub = []): array
{
    $altas   = array_values(array_filter($ev, fn($x) => $x['tipo'] === 'alta'));
    $cambios = array_values(array_filter($ev, fn($x) => $x['tipo'] === 'cambio'));
    $fecha   = $ev[0]['fecha_archivo'] ?? ($pub[0]['fecha_archivo'] ?? null) ?: date('Y-m-d');

    /* El asunto lo manda lo urgente si lo hay: es lo que tiene plazo. Si solo
       hay publicación nueva, el asunto es la publicación, que es la señal de
       «vuelve a barrer». */
    if ($ev) {
        $piezas = [];
        if ($altas)   $piezas[] = count($altas) . ' nuevo' . (count($altas) === 1 ? '' : 's') . ' en la lista';
        if ($cambios) $piezas[] = count($cambios) . ' pasa' . (count($cambios) === 1 ? '' : 'n') . ' a definitivo';
        $asunto = 'SAT 69-B: ' . implode(' y ', $piezas) . ' (' . aviso_fecha_larga($fecha) . ')';
    } else {
        $asunto = count($pub) === 1
            ? 'El SAT publicó una versión nueva de ' . aviso_etiqueta($pub[0]['lista'])
            : 'El SAT publicó ' . count($pub) . ' listados nuevos';
    }

    return ['asunto' => $asunto, 'cuerpo' => aviso_cuerpo($ev, $altas, $cambios, $fecha, $pub)];
}

function aviso_etiqueta(string $clave): string
{
    require_once __DIR__ . '/fuentes.php';
    return FUENTES_CATALOGO[$clave][2] ?? $clave;
}

/** Asuntos con acentos: sin esto llegan como =?ISO? roto en algunos clientes. */
function aviso_codificar(string $texto): string
{
    return '=?UTF-8?B?' . base64_encode($texto) . '?=';
}

function aviso_fecha_larga(string $iso): string
{
    static $meses = [1=>'enero','febrero','marzo','abril','mayo','junio','julio',
                     'agosto','septiembre','octubre','noviembre','diciembre'];
    $t = strtotime($iso);
    return $t ? sprintf('%d de %s de %d', (int)date('j', $t), $meses[(int)date('n', $t)], (int)date('Y', $t))
              : $iso;
}

function aviso_cuerpo(array $ev, array $altas, array $cambios, string $fecha, array $pub = []): string
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $t = '';

    /* --- publicaciones nuevas -------------------------------------------
       Va primero aunque no haya nada urgente: es la señal de que hay que
       volver a pasar la cartera de clientes por la consulta por lote. */
    if ($pub) {
        $t .= '<p style="margin:0 0 10px">El SAT publicó '
            . (count($pub) === 1 ? 'una versión nueva de este listado'
                                 : 'versiones nuevas de estos ' . count($pub) . ' listados')
            . ':</p>';
        $t .= '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px">';
        foreach ($pub as $p) {
            $mov = [];
            $cuenta = fn(int $n, string $uno, string $varios) =>
                number_format($n) . ' ' . ($n === 1 ? $uno : $varios);
            if ((int)$p['altas'])   $mov[] = $cuenta((int)$p['altas'],   'alta',   'altas');
            if ((int)$p['cambios']) $mov[] = $cuenta((int)$p['cambios'], 'cambio', 'cambios');
            if ((int)$p['bajas'])   $mov[] = $cuenta((int)$p['bajas'],   'baja',   'bajas');
            $t .= '<tr>'
                . '<td style="padding:7px 10px 7px 0;border-bottom:1px solid #E4EAF0"><b>'
                . $h(aviso_etiqueta($p['lista'])) . '</b><br>'
                . '<span style="color:#5A6B7B;font-size:12.5px">'
                . ($p['fecha_archivo']
                    ? 'información al ' . $h(aviso_fecha_larga($p['fecha_archivo']))
                    : 'el archivo no declara fecha; publicado el '
                      . $h(aviso_fecha_larga(substr((string)$p['fecha_servidor'], 0, 10))))
                . '</span></td>'
                . '<td style="padding:7px 0;border-bottom:1px solid #E4EAF0;color:#5A6B7B;
                     white-space:nowrap;text-align:right">'
                . ($mov ? $h(implode(' · ', $mov)) : 'sin cambios en los contribuyentes')
                . '</td></tr>';
        }
        $t .= '</table>';
        $t .= '<p style="margin:14px 0 0"><a href="https://insusermx.com/listas/lote.php"
                style="color:#1D6FA5;font-weight:600">Volver a pasar la cartera por la consulta por lote</a></p>';
        if ($ev) $t .= '<hr style="border:0;border-top:1px solid #E4EAF0;margin:22px 0">';
    }

    if ($ev) {
        $t .= '<p style="margin:0 0 16px">El listado del artículo 69-B publicado por el
               SAT con información al <b>' . $h(aviso_fecha_larga($fecha)) . '</b> trae
               movimientos que conviene revisar hoy.</p>';
    }

    if ($altas) {
        $t .= '<p style="margin:0 0 8px"><b>' . count($altas) . '</b> contribuyente'
            . (count($altas) === 1 ? '' : 's') . ' entra' . (count($altas) === 1 ? '' : 'n')
            . ' en la lista:</p>' . aviso_tabla($altas);
    }
    if ($cambios) {
        $t .= '<p style="margin:18px 0 8px"><b>' . count($cambios) . '</b> pasa'
            . (count($cambios) === 1 ? '' : 'n') . ' a definitivo:</p>' . aviso_tabla($cambios);
    }

    // La nota del plazo solo tiene sentido si hay algo urgente: en una
    // republicación del Artículo 69 no corre ningún plazo del 69-B.
    if ($ev) {
    $t .= '<p style="margin:20px 0 0;font-size:13px;color:#5A6B7B">
            El plazo del artículo 69-B para manifestar lo que a su derecho convenga
            corre desde la última de las notificaciones —buzón tributario, página
            del SAT y DOF—, así que la fecha de arriba marca el arranque, no el
            final. Conviene confirmar la publicación en el DOF antes de contar días.</p>';

    $t .= '<p style="margin:16px 0 0"><a href="https://insusermx.com/listas/alertas.php"
            style="color:#1D6FA5">Ver el detalle en el panel</a></p>';
    }

    $titulo = $ev ? 'Movimientos en el listado 69-B'
                  : (count($pub) === 1 ? 'Publicación nueva del SAT' : 'Publicaciones nuevas del SAT');
    return aviso_plantilla($titulo, $t);
}

function aviso_tabla(array $filas): string
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $t = '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px">';
    foreach (array_slice($filas, 0, AVISO_MAX_FILAS) as $f) {
        $detalle = $f['tipo'] === 'cambio'
            ? $h($f['situacion_anterior']) . ' &rarr; ' . $h($f['situacion_nueva'])
            : $h($f['situacion_nueva']);
        $t .= '<tr>'
            . '<td style="padding:7px 10px 7px 0;border-bottom:1px solid #E4EAF0;
                          font-family:Consolas,monospace;white-space:nowrap"><b>' . $h($f['rfc']) . '</b></td>'
            . '<td style="padding:7px 10px 7px 0;border-bottom:1px solid #E4EAF0">' . $h(mb_substr((string)$f['nombre'], 0, 60)) . '</td>'
            . '<td style="padding:7px 0;border-bottom:1px solid #E4EAF0;color:#5A6B7B;white-space:nowrap">' . $detalle . '</td>'
            . '</tr>';
    }
    $t .= '</table>';

    $sobran = count($filas) - AVISO_MAX_FILAS;
    if ($sobran > 0) {
        $t .= '<p style="margin:8px 0 0;font-size:13px;color:#5A6B7B">y ' . $sobran
            . ' más — están todos en el panel.</p>';
    }
    return $t;
}

function aviso_plantilla(string $titulo, string $contenido): string
{
    $h = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html lang="es-MX"><body style="margin:0;padding:24px;
        background:#F4F7FA;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#0A2540">
      <div style="max-width:620px;margin:0 auto;background:#fff;border:1px solid #DCE4EC;
                  border-radius:10px;padding:26px 28px">
        <p style="margin:0 0 4px;font-size:11px;letter-spacing:.10em;text-transform:uppercase;
                  color:#1D6FA5;font-weight:700">International Support Services</p>
        <h1 style="margin:0 0 18px;font-size:19px;color:#0A2540">' . $h . '</h1>'
        . $contenido .
      '</div>
      <p style="max-width:620px;margin:14px auto 0;font-size:11.5px;color:#7A8896">
        Aviso automático de la herramienta de listas del SAT. Uso interno del despacho.</p>
    </body></html>';
}
