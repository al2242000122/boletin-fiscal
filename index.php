<?php
/* ============================================================================
   index.php — pantalla de acceso y portal.
   Sin sesión muestra el formulario; con sesión, las herramientas.
   ============================================================================ */
require __DIR__ . '/acceso.php';
acceso_iniciar();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!acceso_configurado()) {
        $error = 'Falta configurar la contraseña. Suba _configurar.php y siga las instrucciones.';
    } elseif (($falta = acceso_bloqueado()) > 0) {
        $error = 'Demasiados intentos fallidos. Vuelva a intentarlo en '
               . ceil($falta / 60) . ' minuto(s).';
    } elseif (acceso_entrar($_POST['usuario'] ?? '', $_POST['clave'] ?? '', $_POST['token'] ?? '')) {
        header('Location: index.php');   // redirige para que F5 no reenvíe el formulario
        exit;
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}

$dentro = acceso_activo();
header('Cache-Control: private, no-store');
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>International Support Services, S.C. — Herramientas</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="css/portal.css">
</head>

<body>

<?php if (!$dentro): ?>
<!-- ====================== PANTALLA DE ACCESO ====================== -->
<main class="acceso">
  <form class="acceso-caja" method="post" action="index.php" autocomplete="on">
    <div class="marca marca-acceso">
      <div class="marca-sigla" aria-hidden="true">ISS</div>
      <div class="marca-nombre">
        <b>International Support Services, S.C.</b>
        <span>Contadores públicos</span>
      </div>
    </div>

    <h1>Herramientas del despacho</h1>
    <p class="acceso-nota">Acceso restringido al personal del despacho.</p>

    <?php if ($error !== ''): ?>
      <p class="acceso-error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <label for="usuario">Usuario</label>
    <input type="text" id="usuario" name="usuario" autocomplete="username"
           required autofocus value="<?= esc($_POST['usuario'] ?? '') ?>">

    <label for="clave">Contraseña</label>
    <input type="password" id="clave" name="clave" autocomplete="current-password" required>

    <input type="hidden" name="token" value="<?= esc(acceso_token()) ?>">
    <button type="submit" class="btn-acceso">Entrar</button>
  </form>
</main>

<?php else: ?>
<!-- ====================== PORTAL ====================== -->
<header class="cabecera">
  <div class="contenedor">
    <div class="marca">
      <div class="marca-sigla" aria-hidden="true">ISS</div>
      <div class="marca-nombre">
        <b>International Support Services, S.C.</b>
        <span>Contadores públicos</span>
      </div>
    </div>
    <p class="cabecera-contacto">
      <a href="salir.php">Cerrar sesión</a>
    </p>
  </div>
</header>

<section class="portada">
  <div class="contenedor">
    <div class="portada-regla"></div>
    <h1>Herramientas del despacho</h1>
    <p>
      Espacio de trabajo interno. Desde aquí se accede a las herramientas que
      el equipo usa para preparar y publicar los documentos del despacho.
    </p>
  </div>
</section>

<main class="seccion">
  <div class="contenedor">
    <h2 class="seccion-titulo">Disponibles</h2>
    <p class="seccion-nota">
      Cada herramienta abre en su propia página. Lo que se edita se guarda en
      este equipo; nada se envía a un servidor.
    </p>

    <div class="rejilla">
      <a class="tarjeta" href="boletin.php">
        <div class="tarjeta-icono" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
               stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 3h11l5 5v13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/>
            <path d="M15 3v5h5"/>
            <path d="M7 13h10M7 17h7"/>
          </svg>
        </div>
        <h2>Generador de boletines fiscales</h2>
        <p>
          Arma el boletín mensual sobre cuatro plantillas, lo edita en pantalla
          y lo exporta en PNG o PDF listo para enviar.
        </p>
        <span class="tarjeta-pie">Abrir</span>
      </a>
    </div>

    <h2 class="seccion-titulo seccion-titulo-2">En desarrollo</h2>
    <p class="seccion-nota">Anunciadas aquí para tenerlas a la vista. Todavía no abren.</p>

    <div class="rejilla">
      <div class="tarjeta tarjeta-pendiente">
        <div class="tarjeta-icono" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
               stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 5h10M4 9h7M4 13h5"/><circle cx="15.5" cy="14.5" r="4.2"/>
            <path d="m18.7 17.7 2.6 2.6"/>
          </svg>
        </div>
        <h2>Artículo 69, 69-B y 69-B Bis</h2>
        <p>
          Consulta de RFC contra los listados que publica el SAT: créditos
          firmes y no localizados, operaciones inexistentes y transmisión
          indebida de pérdidas fiscales.
        </p>
        <span class="insignia">En desarrollo</span>
      </div>

      <div class="tarjeta tarjeta-pendiente">
        <div class="tarjeta-icono" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
               stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 9h14l-3.5-3.5"/><path d="M20 15H6l3.5 3.5"/>
          </svg>
        </div>
        <h2>API de tipo de cambio</h2>
        <p>
          Tipo de cambio publicado en el DOF, consultable por fecha y
          disponible para las demás herramientas del portal.
        </p>
        <span class="insignia">En desarrollo</span>
      </div>

      <div class="tarjeta tarjeta-pendiente">
        <div class="tarjeta-icono" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
               stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="7" height="16" rx="1.2"/>
            <rect x="14" y="4" width="7" height="16" rx="1.2"/>
            <path d="M10.4 12h3.2"/>
          </svg>
        </div>
        <h2>Conciliador</h2>
        <p>
          Cruce de registros contra auxiliares para detectar diferencias y
          dejar el papel de trabajo armado.
        </p>
        <span class="insignia">En desarrollo</span>
      </div>
    </div>
  </div>
</main>

<footer class="pie">
  <div class="contenedor">
    <p><b>International Support Services, S.C.</b><br>Uso interno del despacho.</p>
    <p><a href="salir.php">Cerrar sesión</a></p>
  </div>
</footer>
<?php endif; ?>

</body>
</html>
