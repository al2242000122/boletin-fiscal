<?php
/* ============================================================================
   boletin.php — sirve el generador solo si hay sesión.

   La aplicación vive en privado/boletin.html, fuera del alcance del navegador
   (el .htaccess bloquea esa carpeta). Es el archivo único que produce
   build.mjs: lleva el CSS, el JS y la fotografía dentro, así que no hay
   archivos sueltos que se puedan pedir por separado saltándose el acceso.
   ============================================================================ */
require __DIR__ . '/acceso.php';
acceso_exigir();

$archivo = __DIR__ . '/privado/boletin.html';

if (!is_file($archivo)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Falta privado/boletin.html.\n"
       . "Ejecute 'node build.mjs' dentro de boletin/ y suba el archivo generado.");
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');
readfile($archivo);
