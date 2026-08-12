<?php
/* ============================================================================
   _configurar.php — genera el hash de la contraseña. Se usa UNA vez.

   En cuanto acceso.php tenga un hash de verdad, este archivo deja de
   funcionar solo. Aun así, BÓRRALO del servidor cuando termines.
   ============================================================================ */
require __DIR__ . '/acceso.php';

if (acceso_configurado()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Ya hay una contraseña configurada. Borre este archivo del servidor.");
}

$hash = '';
$corta = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clave = (string)($_POST['clave'] ?? '');
    if (strlen($clave) < 10) {
        $corta = true;
    } else {
        $hash = password_hash($clave, PASSWORD_DEFAULT);
    }
}
header('Cache-Control: private, no-store');
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configurar contraseña</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="css/portal.css">
</head>
<body>
<main class="acceso">
  <form class="acceso-caja" method="post" action="_configurar.php">
    <h1>Configurar contraseña</h1>
    <p class="acceso-nota">
      Se usa una sola vez. Escriba la contraseña que usará el despacho y copie
      el resultado en <code>acceso.php</code>. La contraseña no se guarda en
      ningún sitio: solo su hash.
    </p>

    <?php if ($corta): ?>
      <p class="acceso-error" role="alert">Use al menos 10 caracteres.</p>
    <?php endif; ?>

    <label for="clave">Contraseña</label>
    <input type="password" id="clave" name="clave" required minlength="10" autofocus>
    <button type="submit" class="btn-acceso">Generar</button>

    <?php if ($hash !== ''): ?>
      <p class="acceso-nota" style="margin-top:22px">
        Copie esta línea y sustituya la que está en <code>acceso.php</code>:
      </p>
      <textarea class="acceso-hash" rows="3" readonly onclick="this.select()"
        >const ACCESO_HASH = '<?= esc($hash) ?>';</textarea>
      <p class="acceso-nota">Después, borre este archivo del servidor.</p>
    <?php endif; ?>
  </form>
</main>
</body>
</html>
