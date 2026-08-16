<?php
/* ============================================================================
   cabecera.php — la barra de navegación de las herramientas, en un solo sitio.

   Estaba escrita a mano en cada pantalla y las seis habían divergido: el mismo
   destino se llamaba «Consultar RFC», «Consultar un RFC» o «Listas del SAT»
   según la página; «Por lote» era «Consulta por lote» en otra; una no tenía
   enlace al portal; y las dos del DOF no enlazaban ni a las alertas ni al lote,
   así que quedaban en una isla. Cada pantalla nueva empeoraba el desorden.

   Aquí el orden y los nombres se deciden una vez. La pantalla en la que se está
   se marca con aria-current, que además es lo que un lector de pantalla
   necesita para decir dónde estás.
   ============================================================================ */

const TOOLS_NAV = [
    'consulta'      => ['consulta.php',      'Consultar RFC'],
    'lote'          => ['lote.php',          'Por lote'],
    'alertas'       => ['alertas.php',       'Alertas'],
    'tipo-cambio'   => ['tipo-cambio.php',   'Tipo de cambio'],
    'equivalencias' => ['equivalencias.php', 'Equivalencias'],
    'index'         => ['index.php',         'Administración'],
];

/**
 * Pinta la cabecera.
 * $activa: la clave de TOOLS_NAV en la que estamos, o '' si ninguna.
 */
function cabecera(string $activa, string $titulo, string $subtitulo): void
{
    ?>
    <header class="cabecera">
      <div class="contenedor">
        <div class="marca">
          <div class="marca-sigla" aria-hidden="true">ISS</div>
          <div class="marca-nombre">
            <b><?= esc($titulo) ?></b>
            <span><?= esc($subtitulo) ?></span>
          </div>
        </div>
        <nav class="cabecera-contacto" aria-label="Herramientas">
          <?php $piezas = [];
          foreach (TOOLS_NAV as $clave => [$destino, $texto]) {
              $piezas[] = sprintf('<a href="%s"%s>%s</a>', esc($destino),
                  $clave === $activa ? ' aria-current="page"' : '', esc($texto));
          }
          $piezas[] = '<a href="../index.php">Portal</a>';
          echo implode(' · ', $piezas); ?>
        </nav>
      </div>
    </header>
    <?php
}

/** El pie, idéntico en las seis pantallas. */
function pie(): void
{
    ?>
    <footer class="pie">
      <div class="contenedor">
        <p><b>International Support Services, S.C.</b><br>Uso interno del despacho.</p>
        <p><a href="../salir.php">Cerrar sesión</a></p>
      </div>
    </footer>
    <?php
}
