<?php
/* ============================================================================
   bd.php — conexión a MySQL.

   Las credenciales viven en privado/config.php, que está fuera del repositorio
   (.gitignore) y fuera del alcance del navegador (.htaccess). Nunca aquí.
   ============================================================================ */

function bd_config(): array
{
    // Permite apuntar a otra base sin tocar config.php. Se usa para pruebas.
    if (getenv('BD_HOST') !== false) {
        return [
            'host'    => getenv('BD_HOST'),
            'nombre'  => getenv('BD_NOMBRE') ?: 'pruebas',
            'usuario' => getenv('BD_USUARIO') ?: 'root',
            'clave'   => getenv('BD_CLAVE') ?: '',
            'puerto'  => (int)(getenv('BD_PUERTO') ?: 3306),
            'charset' => 'utf8mb4',
        ];
    }

    $ruta = __DIR__ . '/../../../privado/config.php';
    if (!is_file($ruta)) {
        throw new RuntimeException(
            "Falta privado/config.php. Copie privado/config.php.ejemplo y ponga ahí " .
            "los datos de la base. NO edite el .ejemplo: ese sí va al repositorio.");
    }
    /* El archivo de configuración lo escribe una persona a mano en el servidor.
       Si se le cuela un espacio o un comentario ANTES de <?php, PHP lo imprime
       y esa salida se cuela en la página: rompe los redirects con "headers
       already sent" y ensucia el diseño. Se captura y se descarta. */
    ob_start();
    require_once $ruta;
    $basura = ob_get_clean();
    if (trim($basura) !== '') {
        $GLOBALS['bd_config_sucia'] = mb_substr(trim($basura), 0, 200);
    }

    return [
        'host'    => defined('BD_HOST')    ? BD_HOST    : 'localhost',
        'nombre'  => defined('BD_NOMBRE')  ? BD_NOMBRE  : '',
        'usuario' => defined('BD_USUARIO') ? BD_USUARIO : '',
        'clave'   => defined('BD_CLAVE')   ? BD_CLAVE   : '',
        'puerto'  => defined('BD_PUERTO')  ? BD_PUERTO  : 3306,
        'charset' => defined('BD_CHARSET') ? BD_CHARSET : 'utf8mb4',
    ];
}

function bd(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $c = bd_config();
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                   $c['host'], $c['puerto'], $c['nombre'], $c['charset']);

    $pdo = new PDO($dsn, $c['usuario'], $c['clave'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Consultas preparadas de verdad, no emuladas: es lo que evita la
        // inyección aunque alguien construya mal una consulta más adelante.
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/** Ejecuta un archivo .sql sentencia por sentencia. */
function bd_ejecutar_sql(string $ruta): int
{
    $sql = file_get_contents($ruta);
    if ($sql === false) throw new RuntimeException("No se pudo leer $ruta");

    // Quita comentarios de línea antes de partir por ';'
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $n = 0;
    foreach (array_filter(array_map('trim', explode(';', $sql)), 'strlen') as $sentencia) {
        bd()->exec($sentencia);
        $n++;
    }
    return $n;
}
