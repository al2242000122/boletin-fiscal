/* ==========================================================================
   exportar.js — PNG e impresión a PDF.
   Lo que se ve es lo que se exporta: antes de capturar se quita el modo edición
   y el escalado de ajuste a pantalla, y después se restauran.
   ========================================================================== */

var Exportar = (function () {

  var ctx = null;   // { hoja, envoltorio, estado(), ajustar(), aviso() }

  function hayHtml2canvas() { return typeof window.html2canvas === 'function'; }

  /* Deja la hoja en estado "limpio" (sin edición ni escalado), ejecuta la
     tarea y restaura. La tarea puede devolver una promesa. */
  function conHojaLimpia(tarea) {
    var hoja = ctx.hoja;
    var editaba = hoja.classList.contains('editando');
    var transformPrevio = hoja.style.transform;
    var anchoPrevio = ctx.envoltorio.style.width;
    var altoPrevio = ctx.envoltorio.style.height;

    if (editaba) Editor.aplicarModo(false);
    if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
    hoja.style.transform = 'none';
    ctx.envoltorio.style.width = hoja.offsetWidth + 'px';
    ctx.envoltorio.style.height = hoja.offsetHeight + 'px';

    function restaurar() {
      hoja.style.transform = transformPrevio;
      ctx.envoltorio.style.width = anchoPrevio;
      ctx.envoltorio.style.height = altoPrevio;
      if (editaba) Editor.aplicarModo(true);
      ctx.ajustar();
    }

    var r;
    try { r = tarea(); }
    catch (e) { restaurar(); throw e; }

    if (r && typeof r.then === 'function') {
      return r.then(function (v) { restaurar(); return v; },
                    function (e) { restaurar(); throw e; });
    }
    restaurar();
    return Promise.resolve(r);
  }

  function nombreArchivo(ext) {
    var e = ctx.estado();
    var mes = String(e.contenido.meta.mes || 'boletin')
      .toLowerCase()
      .replace(/[áä]/g, 'a').replace(/[éë]/g, 'e').replace(/[íï]/g, 'i')
      .replace(/[óö]/g, 'o').replace(/[úü]/g, 'u').replace(/ñ/g, 'n')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    return 'boletin-' + mes + '-plantilla-' + e.plantilla + '.' + ext;
  }

  function descargar(url, nombre) {
    var a = document.createElement('a');
    a.href = url;
    a.download = nombre;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }

  function png() {
    if (!hayHtml2canvas()) {
      ctx.aviso('No se pudo cargar la biblioteca de exportación (requiere internet). Use Imprimir / PDF.', 'error');
      return Promise.resolve(false);
    }
    ctx.aviso('Generando PNG…');
    return conHojaLimpia(function () {
      return window.html2canvas(ctx.hoja, {
        scale: 2,
        backgroundColor: '#ffffff',
        useCORS: true,
        logging: false
      }).then(function (lienzo) {
        descargar(lienzo.toDataURL('image/png'), nombreArchivo('png'));
        return true;
      });
    }).then(function (ok) {
      ctx.aviso(ok ? 'PNG descargado.' : '', 'ok');
      return ok;
    }, function (e) {
      ctx.aviso('No se pudo generar el PNG: ' + (e && e.message ? e.message : e), 'error');
      return false;
    });
  }

  /* @page no admite selectores: la regla de tamaño y orientación se inyecta
     según la plantilla activa justo antes de imprimir. La clase imprimir-N en
     <html> activa además las reglas con selector de print.css. */
  /* margin:0 en las cuatro: el margen de página es donde el navegador dibuja
     su encabezado y su pie (fecha, título, URL, número de página). Sin margen
     no caben. Carta porque es el papel del despacho; las apaisadas están
     dimensionadas para caber también en A4 apaisada. */
  var REGLAS_PAGINA = {
    '1': '@page{ size: letter; margin: 0; }',
    '2': '@page{ size: letter landscape; margin: 0; }',
    '3': '@page{ size: letter; margin: 0; }',
    '4': '@page{ size: letter landscape; margin: 0; }'
  };

  function reglaPagina(plantilla) {
    var id = 'regla-pagina';
    var n = document.getElementById(id);
    if (!n) { n = document.createElement('style'); n.id = id; document.head.appendChild(n); }
    n.textContent = REGLAS_PAGINA[String(plantilla)] || REGLAS_PAGINA['1'];
    var raiz = document.documentElement;
    raiz.className = raiz.className.replace(/\bimprimir-\d\b/g, '').replace(/\s+/g, ' ').trim();
    raiz.classList.add('imprimir-' + plantilla);
  }

  function imprimir() {
    var hoja = ctx.hoja;
    var editaba = hoja.classList.contains('editando');
    if (editaba) Editor.aplicarModo(false);
    if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
    reglaPagina(ctx.estado().plantilla);
    window.print();
    if (editaba) Editor.aplicarModo(true);
  }

  /* ======================================================================
     Guardar y cargar
     ====================================================================== */

  var CLAVE_BORRADOR = 'boletin:borrador';
  var VERSION = 1;

  function revisionActual() {
    return (typeof CONTENIDO_REVISION === 'string') ? CONTENIDO_REVISION : null;
  }

  function instantanea() {
    var e = ctx.estado();
    return {
      version: VERSION,
      revision: revisionActual(),   // contra qué texto base se editó esto
      plantilla: e.plantilla,
      tema: e.tema,
      contenido: e.contenido
    };
  }

  function guardarJSON() {
    Editor.volcarPendiente();
    var texto = JSON.stringify(instantanea(), null, 2);
    var blob = new Blob([texto], { type: 'application/json' });
    var url = URL.createObjectURL(blob);
    descargar(url, nombreArchivo('json').replace(/-plantilla-\d/, ''));
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    ctx.aviso('Boletín guardado en JSON.', 'ok');
  }

  /* Validación mínima: un JSON de otro programa no debe dejar la app en un
     estado a medias. O trae las 6 secciones y el pie, o no se carga. */
  function valido(o) {
    if (!o || typeof o !== 'object' || !o.contenido) return false;
    var c = o.contenido;
    return !!(c.meta && c.pie && typeof c.gancho === 'string' &&
              Array.isArray(c.secciones) && c.secciones.length &&
              c.secciones.every(function (s) { return s && s.id && s.titulo; }));
  }

  function cargarJSON(archivo, alCargar) {
    var lector = new FileReader();
    lector.onload = function () {
      var o;
      try { o = JSON.parse(lector.result); }
      catch (e) { ctx.aviso('El archivo no es un JSON válido.', 'error'); return; }
      if (!valido(o)) { ctx.aviso('El archivo no es un boletín guardado por esta aplicación.', 'error'); return; }
      alCargar(o);
      ctx.aviso('Boletín cargado.', 'ok');
    };
    lector.onerror = function () { ctx.aviso('No se pudo leer el archivo.', 'error'); };
    lector.readAsText(archivo);
  }

  /* CSS de la aplicación. Servida por HTTP se lee el vivo; en file:// Chrome
     bloquea cssRules con SecurityError y se usa la copia que genera build.mjs. */
  function css() {
    var partes = [], vivo = true;
    for (var i = 0; i < document.styleSheets.length; i++) {
      try {
        var reglas = document.styleSheets[i].cssRules;
        for (var j = 0; j < reglas.length; j++) partes.push(reglas[j].cssText);
      } catch (e) { vivo = false; break; }
    }
    if (vivo && partes.length) return partes.join('\n');
    return (typeof ESTILOS_EMBEBIDOS === 'string') ? ESTILOS_EMBEBIDOS : null;
  }

  /* Entregable congelado: la hoja tal cual se ve, sin barra y sin edición. */
  function guardarHTML() {
    Editor.volcarPendiente();
    var hojas = css();
    if (!hojas) {
      ctx.aviso('Falta js/estilos.js: ejecute "node build.mjs" una vez para poder guardar HTML.', 'error');
      return;
    }
    var e = ctx.estado();
    var clon = ctx.hoja.cloneNode(true);
    clon.classList.remove('editando');
    clon.style.transform = '';
    clon.removeAttribute('style');
    clon.style.margin = '0 auto';
    var quitar = clon.querySelectorAll('.solo-edicion');
    for (var i = 0; i < quitar.length; i++) quitar[i].parentNode.removeChild(quitar[i]);
    var editables = clon.querySelectorAll('[contenteditable]');
    for (var k = 0; k < editables.length; k++) {
      editables[k].removeAttribute('contenteditable');
      editables[k].removeAttribute('spellcheck');
    }
    var p = Plantillas.obtener(e.plantilla);
    clon.style.width = p.ancho + 'px';
    clon.style.height = p.alto + 'px';

    var doc = '<!doctype html>\n<html lang="es-MX">\n<head>\n<meta charset="utf-8">\n' +
      '<title>Boletín fiscal · ' + Plantillas.esc(e.contenido.meta.mes) + '</title>\n' +
      '<style>\n' + hojas + '\n' +
      'body{margin:0;background:#f2f4f7;display:flex;justify-content:center;' +
      'align-items:flex-start;padding:24px}\n' +
      '@media print{body{background:#fff;padding:0;display:block}}\n' +
      '</style>\n</head>\n<body class="imprimir-' + e.plantilla + '">\n' +
      clon.outerHTML + '\n</body>\n</html>\n';

    var blob = new Blob([doc], { type: 'text/html;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    descargar(url, nombreArchivo('html'));
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    ctx.aviso('HTML guardado (hoja congelada, sin barra ni edición).', 'ok');
  }

  /* §10.6 — localStorage puede no existir en file:// con configuraciones
     estrictas. Todo va envuelto y se degrada en silencio al JSON manual. */
  function autoguardar() {
    try { localStorage.setItem(CLAVE_BORRADOR, JSON.stringify(instantanea())); return true; }
    catch (e) { return false; }
  }

  function leerBorrador() {
    try {
      var s = localStorage.getItem(CLAVE_BORRADOR);
      if (!s) return null;
      var o = JSON.parse(s);
      return valido(o) ? o : null;
    } catch (e) { return null; }
  }

  function borrarBorrador() {
    try { localStorage.removeItem(CLAVE_BORRADOR); } catch (e) { /* da igual */ }
  }

  return {
    iniciar: function (contexto) { ctx = contexto; },
    hayHtml2canvas: hayHtml2canvas,
    conHojaLimpia: function (t) { return conHojaLimpia(t); },
    reglaPagina: reglaPagina,
    png: png,
    imprimir: imprimir,
    revisionActual: revisionActual,
    /* Vuelve a sellar el borrador con la revisión de hoy, para que el aviso de
       "el texto base cambió" no reaparezca si el usuario decide conservarlo. */
    sellarBorrador: function () {
      var b = leerBorrador();
      if (!b) return false;
      b.revision = revisionActual();
      try { localStorage.setItem(CLAVE_BORRADOR, JSON.stringify(b)); return true; }
      catch (e) { return false; }
    },
    guardarJSON: guardarJSON,
    cargarJSON: cargarJSON,
    guardarHTML: guardarHTML,
    autoguardar: autoguardar,
    leerBorrador: leerBorrador,
    borrarBorrador: borrarBorrador,
    valido: valido
  };
})();
