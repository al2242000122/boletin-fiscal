<?php
/* ============================================================================
   04_delta.php — el simulacro de publicación. La prueba que más vale.

   El 69-B se publica cada uno o dos meses. Sin esto, la cadena completa
   —descarga, lectura, diferencias, línea base, prioridades— solo se puede
   probar esperando a que el SAT publique otra vez.

   Aquí se fabrica esa publicación: se carga mayo, se carga junio, y junio trae
   exactamente lo que el sistema tiene que detectar —dos que pasan a definitivo,
   uno que desaparece, uno nuevo— más un registro suprimido y un RFC con Ñ que
   no deben estorbar.

   Necesita una base de datos, y a propósito NO usa privado/config.php: solo
   corre si se le pasa una por variables de entorno. Así no puede tocar la de
   producción por descuido.

       BD_HOST=127.0.0.1 BD_PUERTO=3399 BD_NOMBRE=pruebas BD_USUARIO=root \
           php listas/pruebas/correr.php
   ============================================================================ */

if (getenv('BD_HOST') === false) {
    omitir('simulacro de publicación completo',
           'sin BD_HOST: se omite para no tocar la base de producción');
    return;
}
if (!in_array('file', curl_version()['protocols'] ?? [], true)) {
    omitir('simulacro de publicación completo', 'este curl no soporta file://');
    return;
}

require_once __DIR__ . '/../cron/lib/ingestor.php';

const LISTA_PRUEBA = 'prueba.delta';

/* --- suelo limpio ------------------------------------------------------ */
bd_ejecutar_sql(__DIR__ . '/../cron/esquema.sql');
migrar_columnas_pendientes();
limpiar_prueba();

function limpiar_prueba(): void
{
    foreach (['eventos', 'estatus', 'snapshots', 'ingestas'] as $t) {
        bd()->prepare("DELETE FROM $t WHERE lista = ?")->execute([LISTA_PRUEBA]);
    }
}

/** Carga uno de los CSV de casos/ como si el SAT lo acabara de publicar. */
function publicar(string $archivo): array
{
    $ruta = str_replace('\\', '/', realpath(caso($archivo)));
    return ingerir(LISTA_PRUEBA,
        ['url' => 'file:///' . ltrim($ruta, '/'), 'grupo' => 'art69b', 'etiqueta' => 'Simulacro'],
        false, function ($l) {});
}

function contar(string $sql, array $par = []): int
{
    $st = bd()->prepare($sql);
    $st->execute($par);
    return (int)$st->fetchColumn();
}

/* ==========================================================================
   Mayo: la primera carga. Es el punto de partida, no movimiento.
   ========================================================================== */
publicar('delta_mayo.csv');

$snapMayo = bd()->query("SELECT * FROM snapshots WHERE lista = '" . LISTA_PRUEBA . "'
                         ORDER BY id DESC LIMIT 1")->fetch();

comprobar('mayo: se lee la fecha del preámbulo', '2026-05-31', $snapMayo['fecha_archivo']);
comprobar('mayo: la primera carga es línea base', 1, (int)$snapMayo['linea_base']);
comprobar('mayo: entran 4 contribuyentes', 4,
    contar("SELECT COUNT(*) FROM estatus WHERE lista = ? AND vigente = 1", [LISTA_PRUEBA]));

// El registro tachado por el SAT se cuenta como suprimido y no entra: si
// entrara, XXXXXXXXXXXX sería un contribuyente más y aparecería en las
// consultas como si fuera un RFC.
comprobar('mayo: el registro suprimido no entra en estatus', 0,
    contar("SELECT COUNT(*) FROM estatus WHERE rfc LIKE 'XXXX%'"));
comprobar('mayo: el RFC con Ñ sí entra', 1,
    contar("SELECT COUNT(*) FROM estatus WHERE rfc = 'ÑAÑ030303CCC' AND vigente = 1"));

// La carga inicial genera altas, pero no son noticia: son el arranque.
comprobar('mayo: ninguna alerta de la carga inicial', 0,
    contar("SELECT COUNT(*) FROM eventos WHERE lista = ? AND prioridad > 0", [LISTA_PRUEBA]));

/* ==========================================================================
   Junio: la publicación siguiente. Aquí sí hay movimiento.
   ========================================================================== */
publicar('delta_junio.csv');

$snapJunio = bd()->query("SELECT * FROM snapshots WHERE lista = '" . LISTA_PRUEBA . "'
                          ORDER BY id DESC LIMIT 1")->fetch();

comprobar('junio: se lee su propia fecha',   '2026-06-30', $snapJunio['fecha_archivo']);
comprobar('junio: NO es línea base',          0, (int)$snapJunio['linea_base']);

$ev = fn(string $tipo) => contar(
    "SELECT COUNT(*) FROM eventos WHERE snapshot_id = ? AND tipo = ?", [$snapJunio['id'], $tipo]);

comprobar('junio: 1 alta',    1, $ev('alta'));
comprobar('junio: 2 cambios', 2, $ev('cambio'));
comprobar('junio: 1 baja',    1, $ev('baja'));

// El orden del cálculo importa: si las altas se contaran después de cerrar las
// filas que cambiaron, cada cambio generaría además un alta fantasma. Medido en
// su momento: 5 cambios producían 5 altas de más.
comprobar('junio: los cambios no generan altas fantasma', 1, $ev('alta'));

comprobar('junio: 3 movimientos urgentes', 3,
    contar("SELECT COUNT(*) FROM eventos WHERE snapshot_id = ? AND prioridad = 2", [$snapJunio['id']]));

/* --- el historial se conserva ------------------------------------------
   Es lo que permite responder "al 31 de mayo este RFC constaba como presunto",
   que es el entregable que sirve en una revisión. */
$bbb = bd()->query("SELECT situacion, valido_desde, valido_hasta, vigente FROM estatus
                    WHERE rfc = 'BBB020202BBB' ORDER BY valido_desde")->fetchAll();
comprobar('BBB tiene dos filas: la vieja cerrada y la nueva abierta', 2, count($bbb));
comprobar('la vieja decía Presunto',      'Presunto',   $bbb[0]['situacion']);
comprobar('y se cerró el 30 de junio',    '2026-06-30', $bbb[0]['valido_hasta']);
comprobar('la nueva dice Definitivo',     'Definitivo', $bbb[1]['situacion']);
comprobar('y es la vigente',              1,            (int)$bbb[1]['vigente']);

$ddd = bd()->query("SELECT valido_hasta, vigente FROM estatus WHERE rfc = 'DDD040404DDD'")->fetch();
comprobar('el que desapareció queda cerrado, no borrado', '2026-06-30', $ddd['valido_hasta']);
comprobar('y deja de estar vigente',                      null,         $ddd['vigente']);

comprobar('el nuevo entra como presunto', 'Presunto',
    bd()->query("SELECT situacion FROM estatus WHERE rfc = 'EEE050505EEE' AND vigente = 1")->fetchColumn());

comprobar('siguen siendo 4 vigentes', 4,
    contar("SELECT COUNT(*) FROM estatus WHERE lista = ? AND vigente = 1", [LISTA_PRUEBA]));

/* ==========================================================================
   Idempotencia: volver a cargar el mismo archivo no puede duplicar nada.
   ========================================================================== */
$antes = contar("SELECT COUNT(*) FROM eventos WHERE lista = ?", [LISTA_PRUEBA]);
$r = publicar('delta_junio.csv');
comprobar('recargar el mismo archivo no genera eventos', 0, $r['eventos']);
comprobar('ni deja filas de más', $antes,
    contar("SELECT COUNT(*) FROM eventos WHERE lista = ?", [LISTA_PRUEBA]));
comprobar('ni un snapshot nuevo', (int)$snapJunio['id'],
    contar("SELECT MAX(id) FROM snapshots WHERE lista = ?", [LISTA_PRUEBA]));

/* --- recoger --------------------------------------------------------- */
limpiar_prueba();
$archivado = RAIZ_ARCHIVO . '/' . LISTA_PRUEBA;
if (is_dir($archivado)) {
    foreach (glob("$archivado/*") as $f) unlink($f);
    rmdir($archivado);
}
