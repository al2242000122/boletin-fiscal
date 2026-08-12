/* ==========================================================================
   build.mjs — Node sin dependencias.  Uso:  node build.mjs

   Produce dos cosas:

   1) js/estilos.js  — todo el CSS como una cadena de JavaScript.
      Hace falta para "Guardar HTML": en file:// Chrome no deja leer
      document.styleSheets[].cssRules de las hojas enlazadas (SecurityError),
      así que la aplicación no puede recuperar su propio CSS por sí sola.
      Servida por HTTP sí puede, y en ese caso prefiere el CSS vivo.

   2) dist/boletin.html — la aplicación entera en un archivo: CSS y JS
      incrustados y la fotografía en base64. Es lo que el despacho puede
      mandar por correo o guardar en Drive.

   Volver a ejecutarlo después de tocar cualquier CSS.
   ========================================================================== */

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = dirname(fileURLToPath(import.meta.url));
const leer = (...p) => readFileSync(join(RAIZ, ...p), 'utf8');

/* El orden importa: tokens primero, print al final. Es el mismo de index.html. */
const CSS = [
  'tokens.css', 'app.css',
  'plantilla-1.css', 'plantilla-2.css', 'plantilla-3.css', 'plantilla-4.css',
  'print.css',
];

/* foto-default.js y estilos.js son generados; el resto es código fuente. */
const JS = [
  'foto-default.js', 'estilos.js', 'contenido.js',
  'plantillas.js', 'editor.js', 'exportar.js', 'app.js',
];

function juntarCSS() {
  return CSS.map(f => '/* ==== ' + f + ' ==== */\n' + leer('css', f)).join('\n\n');
}

/* --- 1) js/estilos.js ------------------------------------------------------ */
const css = juntarCSS();
writeFileSync(
  join(RAIZ, 'js', 'estilos.js'),
  '/* GENERADO POR build.mjs — no editar a mano.\n' +
  '   Copia del CSS para poder incrustarlo al "Guardar HTML" en file://.\n' +
  '   Si tocas un archivo de css/, vuelve a ejecutar: node build.mjs */\n' +
  'var ESTILOS_EMBEBIDOS = ' + JSON.stringify(css) + ';\n',
  'utf8'
);
console.log('js/estilos.js        ' + Math.round(css.length / 1024) + ' KB de CSS');

/* --- 2) dist/boletin.html -------------------------------------------------- */
const js = JS.map(f => '/* ==== ' + f + ' ==== */\n' + leer('js', f)).join('\n;\n');

const html = leer('index.html')
  // Las hojas de estilo enlazadas pasan a un único <style>.
  .replace(/^\s*<link rel="stylesheet"[^>]*>\s*$/gm, '')
  .replace('</head>', '<style>\n' + css + '\n</style>\n</head>')
  // Los scripts locales pasan a un único <script>. El CDN de html2canvas se
  // deja como está: es la única dependencia externa y degrada sola si falla.
  .replace(/^\s*<script src="js\/[^"]*"><\/script>\s*$/gm, '')
  .replace('</body>', '<script>\n' + js + '\n</script>\n</body>');

if (!existsSync(join(RAIZ, 'dist'))) mkdirSync(join(RAIZ, 'dist'));
writeFileSync(join(RAIZ, 'dist', 'boletin.html'), html, 'utf8');
console.log('dist/boletin.html    ' + Math.round(html.length / 1024) + ' KB, un solo archivo');

/* Copia que sirve boletin.php. Vive en privado/, fuera del alcance del
   navegador: sin sesion no hay forma de pedirla por URL. */
const PRIV = join(RAIZ, '..', 'privado');
if (!existsSync(PRIV)) mkdirSync(PRIV);
writeFileSync(join(PRIV, 'boletin.html'), html, 'utf8');
console.log('privado/boletin.html ' + Math.round(html.length / 1024) + ' KB, para el acceso PHP');

/* --- Comprobaciones mínimas ------------------------------------------------ */
const fallos = [];
if (/<link rel="stylesheet"/.test(html)) fallos.push('quedó un <link> de CSS sin incrustar');
if (/<script src="js\//.test(html)) fallos.push('quedó un <script src="js/…"> sin incrustar');
if (!/var CONTENIDO_INICIAL/.test(html)) fallos.push('falta contenido.js');
if (!/var ESTILOS_EMBEBIDOS/.test(html)) fallos.push('falta estilos.js');
if (!/data:image\/jpeg;base64/.test(html)) fallos.push('falta la fotografía en base64');
for (const p of ['1', '2', '3', '4'])
  if (!new RegExp('plantilla-' + p + '\\.css').test(html)) fallos.push('falta plantilla-' + p + '.css');

if (fallos.length) { console.error('FALLOS:\n - ' + fallos.join('\n - ')); process.exit(1); }
console.log('comprobaciones        OK');
