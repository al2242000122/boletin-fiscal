/* ==========================================================================
   app.js — arranque, estado y cableado.
   El estado vive aquí; las plantillas solo lo pintan. Cambiar de plantilla
   repinta con los mismos datos, así que nunca se pierde lo editado.
   ========================================================================== */

var App = (function () {

  var estado = {
    contenido: null,
    plantilla: '1',
    tema: 'azul',
    editando: false
  };

  var hoja, envoltorio, lienzo, avisoCaja, avisoTemporizador, guardadoTemporizador;

  var TEMAS = [
    { id: 'azul',     nombre: 'Azul',     desc: 'Corporativo, imprime bien' },
    { id: 'verde',    nombre: 'Verde',    desc: 'Diferenciación de marca' },
    { id: 'nocturno', nombre: 'Nocturno', desc: 'Solo envío digital; consume tóner' }
  ];

  /* Esquemas de las miniaturas: [arriba, izquierda, ancho, alto, clase] en %.
     Se dibujan con divs, no con imágenes: así funcionan sin internet y siguen
     al tema sin tener que regenerar capturas. */
  var MINIATURAS = {
    '1': [[0,0,100,6,'navy'], [6,0,26,15,'navy2'], [6,26,74,15,'acc'],
          [26,10,80,3,'ln'], [31,10,80,3,'ln'],
          [40,10,35,3,'ln'], [40,55,35,3,'ln'], [45,10,35,3,'ln'], [45,55,35,3,'ln'],
          [56,10,35,3,'ln'], [56,55,35,3,'ln'], [61,10,35,3,'ln'], [61,55,35,3,'ln'],
          [72,8,84,9,'navy2'], [85,0,100,8,'soft'], [95,0,100,5,'navy']],
    '2': [[6,4,40,7,'ln'], [6,78,18,10,'navy'], [20,4,92,3,'acc'],
          [27,0,100,12,'navy'],
          [44,2,16,14,'ln'], [44,22,16,14,'ln'], [44,42,16,14,'ln'], [44,62,16,14,'ln'], [44,82,16,14,'ln'],
          [64,4,26,22,'ln'], [64,36,26,22,'ln'], [64,68,28,22,'soft'],
          [90,0,100,10,'navy2']],
    '3': [[7,8,84,3,'navy'], [12,8,84,1,'navy'],
          [18,8,50,6,'ln'], [28,8,84,10,'soft'],
          [42,8,38,40,'ln'], [42,54,38,18,'ln'], [63,54,38,12,'navy'], [78,54,38,9,'ln'],
          [92,0,100,8,'navy2']],
    '4': [[0,0,27,100,'navy'], [4,3,21,18,'acc'],
          [0,28,72,22,'soft'],
          [24,28,22,54,'ln'], [24,53,22,54,'ln'], [24,78,22,54,'ln'],
          [80,28,72,20,'navy2']]
  };

  /* ---- pintado ----------------------------------------------------------- */
  function pintar() {
    var p = Plantillas.obtener(estado.plantilla);
    if (!p) return;
    hoja.setAttribute('data-plantilla', estado.plantilla);
    hoja.setAttribute('data-tema', estado.tema);
    hoja.style.width = p.ancho + 'px';
    hoja.style.height = p.alto + 'px';
    hoja.innerHTML = p.render(estado.contenido);

    // La foto se asigna por JS y no dentro del HTML: los data:URL son largos
    // y meterlos en una cadena de estilo invita a errores de escapado.
    var fotos = hoja.querySelectorAll('[data-foto]');
    for (var i = 0; i < fotos.length; i++) {
      fotos[i].style.backgroundImage = 'url("' + estado.contenido.foto + '")';
    }

    // Paso de ajuste propio de la plantilla, si lo tiene (la Editorial encoge
    // el cuerpo hasta que cabe en sus dos columnas).
    var factor = p.ajustar ? p.ajustar(hoja) : 1;

    Editor.aplicarModo(estado.editando);
    // El usuario también imprime con Ctrl+P, sin pasar por el botón: las reglas
    // de página tienen que estar puestas siempre, no solo al pulsar Imprimir.
    Exportar.reglaPagina(estado.plantilla);
    ajustar();
    vigilarCapacidad(p, factor);
  }

  /* Una hoja de tamaño fijo puede quedarse corta si el texto crece. Antes que
     recortar en silencio, se avisa. */
  function vigilarCapacidad(p, factor) {
    var rebasa = hoja.scrollWidth > p.ancho + 1 || hoja.scrollHeight > p.alto + 1;
    if (rebasa) {
      aviso('El texto ya no cabe en «' + p.nombre + '»: se está saliendo de la hoja. ' +
            'Acorte algún punto o use otra plantilla.', 'error');
    } else if (factor < 0.9) {
      aviso('El texto es largo para «' + p.nombre + '»: se redujo al ' +
            Math.round(factor * 100) + '% para que cupiera.', 'error');
    }
  }

  /* ---- ajuste a pantalla -------------------------------------------------
     La hoja tiene medidas fijas en px. En pantallas chicas se escala entera
     con transform; nunca se reflowea el contenido. */
  function ajustar() {
    var p = Plantillas.obtener(estado.plantilla);
    if (!p) return;
    var disponible = lienzo.clientWidth - 48;
    var k = Math.min(1, disponible / p.ancho);
    if (!isFinite(k) || k <= 0) k = 1;
    hoja.style.transformOrigin = 'top left';
    hoja.style.transform = k === 1 ? 'none' : 'scale(' + k + ')';
    envoltorio.style.width = Math.round(p.ancho * k) + 'px';
    envoltorio.style.height = Math.round(p.alto * k) + 'px';
  }

  /* ---- avisos ------------------------------------------------------------ */
  function aviso(texto, tipo) {
    if (!avisoCaja) return;
    if (avisoTemporizador) clearTimeout(avisoTemporizador);
    avisoCaja.textContent = texto || '';
    avisoCaja.className = 'aviso' + (tipo ? ' aviso-' + tipo : '') + (texto ? ' visible' : '');
    if (texto) avisoTemporizador = setTimeout(function () {
      avisoCaja.className = 'aviso';
      avisoCaja.textContent = '';
    }, tipo === 'error' ? 8000 : 3500);
  }

  /* ---- galería de plantillas --------------------------------------------- */
  function construirGaleria() {
    var cont = document.getElementById('galeria');
    var ids = Plantillas.listar();
    var html = '';
    for (var i = 0; i < ids.length; i++) {
      var id = ids[i], p = Plantillas.obtener(id);
      var k = Math.min(46 / p.ancho, 30 / p.alto);
      var rects = MINIATURAS[id] || [];
      var interior = '';
      for (var j = 0; j < rects.length; j++) {
        var r = rects[j];
        interior += '<i class="m-' + r[4] + '" style="top:' + r[0] + '%;left:' + r[1] +
          '%;width:' + r[2] + '%;height:' + r[3] + '%"></i>';
      }
      html += '<button type="button" class="plantilla-op" role="radio" data-plantilla="' + id + '"' +
        ' aria-checked="false" title="' + Plantillas.esc(p.nombre) + ' · ' + p.ancho + '×' + p.alto + ' px">' +
        '<span class="mini" style="width:' + Math.round(p.ancho * k) + 'px;height:' +
        Math.round(p.alto * k) + 'px">' + interior + '</span>' +
        '<span class="plantilla-nom">' + Plantillas.esc(p.nombre) + '</span>' +
        '</button>';
    }
    cont.innerHTML = html;
    cont.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-plantilla]');
      if (b) cambiarPlantilla(b.getAttribute('data-plantilla'));
    });
  }

  function marcarGaleria() {
    var ops = document.querySelectorAll('#galeria .plantilla-op');
    for (var i = 0; i < ops.length; i++) {
      var act = ops[i].getAttribute('data-plantilla') === estado.plantilla;
      ops[i].classList.toggle('activa', act);
      ops[i].setAttribute('aria-checked', String(act));
    }
  }

  function cambiarPlantilla(id) {
    if (!Plantillas.obtener(id) || id === estado.plantilla) return;
    // Volcar lo que se esté escribiendo ANTES de repintar: si no, el texto en
    // vuelo se perdería justo al cambiar de plantilla.
    Editor.volcarPendiente();
    estado.plantilla = id;
    pintar();
    marcarGaleria();
    guardarBorrador();
    aviso('Plantilla: ' + Plantillas.obtener(id).nombre + ' · ' +
          Plantillas.obtener(id).ancho + '×' + Plantillas.obtener(id).alto + ' px');
  }

  /* ---- temas -------------------------------------------------------------- */
  function construirTemas() {
    var cont = document.getElementById('temas');
    var html = '';
    for (var i = 0; i < TEMAS.length; i++) {
      html += '<button type="button" class="tema-op" role="radio" data-tema="' + TEMAS[i].id + '"' +
        ' aria-checked="false" title="' + TEMAS[i].nombre + ' — ' + TEMAS[i].desc + '">' +
        '<span class="muestra" data-tema="' + TEMAS[i].id + '"></span>' +
        '<span class="tema-nom">' + TEMAS[i].nombre + '</span>' +
        '</button>';
    }
    cont.innerHTML = html;
    cont.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-tema]');
      if (b) cambiarTema(b.getAttribute('data-tema'));
    });
  }

  function marcarTemas() {
    var ops = document.querySelectorAll('#temas .tema-op');
    for (var i = 0; i < ops.length; i++) {
      var act = ops[i].getAttribute('data-tema') === estado.tema;
      ops[i].classList.toggle('activa', act);
      ops[i].setAttribute('aria-checked', String(act));
    }
  }

  function cambiarTema(id) {
    estado.tema = id;
    hoja.setAttribute('data-tema', id);   // solo sustituye variables: no repinta
    marcarTemas();
    guardarBorrador();
  }

  /* ---- modo edición ------------------------------------------------------ */
  function alternarEdicion(forzar) {
    estado.editando = (typeof forzar === 'boolean') ? forzar : !estado.editando;
    Editor.aplicarModo(estado.editando);
    var b = document.getElementById('btn-editar');
    b.setAttribute('aria-pressed', String(estado.editando));
    b.classList.toggle('activo', estado.editando);
    b.textContent = estado.editando ? 'Terminar edición' : 'Editar';
    document.body.classList.toggle('modo-edicion', estado.editando);
    aviso(estado.editando
      ? 'Modo edición: haga clic sobre cualquier texto para modificarlo.'
      : '');
  }

  /* ---- borrador ----------------------------------------------------------- */
  function guardarBorrador() {
    if (guardadoTemporizador) clearTimeout(guardadoTemporizador);
    guardadoTemporizador = setTimeout(function () { Exportar.autoguardar(); }, 600);
  }

  function aplicarInstantanea(o) {
    estado.contenido = o.contenido;
    if (Plantillas.obtener(o.plantilla)) estado.plantilla = String(o.plantilla);
    if (o.tema) estado.tema = o.tema;
    pintar();
    marcarGaleria();
    marcarTemas();
  }

  /* ---- arranque ---------------------------------------------------------- */
  function iniciar() {
    hoja = document.getElementById('hoja');
    envoltorio = document.getElementById('envoltorio');
    lienzo = document.getElementById('lienzo');
    avisoCaja = document.getElementById('aviso');

    estado.contenido = Datos.clonar(CONTENIDO_INICIAL);

    Editor.iniciar({
      hoja: hoja,
      datos: function () { return estado.contenido; },
      repintar: pintar,
      cambio: guardarBorrador
    });

    Exportar.iniciar({
      hoja: hoja,
      envoltorio: envoltorio,
      estado: function () { return estado; },
      ajustar: ajustar,
      aviso: aviso
    });

    construirGaleria();
    construirTemas();

    // Borrador de la sesión anterior, si lo hay y si localStorage funciona.
    var borrador = Exportar.leerBorrador();
    if (borrador) {
      aplicarInstantanea(borrador);
      document.getElementById('restaurado').hidden = false;
    } else {
      pintar();
      marcarGaleria();
      marcarTemas();
    }

    document.getElementById('btn-editar').addEventListener('click', function () { alternarEdicion(); });
    document.getElementById('btn-png').addEventListener('click', function () {
      Editor.volcarPendiente(); Exportar.png();
    });
    document.getElementById('btn-imprimir').addEventListener('click', function () {
      Editor.volcarPendiente(); Exportar.imprimir();
    });
    document.getElementById('btn-guardar').addEventListener('click', function () {
      Exportar.guardarJSON();
    });
    document.getElementById('btn-html').addEventListener('click', function () {
      Exportar.guardarHTML();
    });

    var entrada = document.getElementById('entrada-json');
    document.getElementById('btn-cargar').addEventListener('click', function () {
      entrada.value = '';
      entrada.click();
    });
    entrada.addEventListener('change', function () {
      if (!entrada.files || !entrada.files[0]) return;
      Exportar.cargarJSON(entrada.files[0], function (o) {
        aplicarInstantanea(o);
        Exportar.autoguardar();
        document.getElementById('restaurado').hidden = true;
      });
    });

    // Descartar borra el único ejemplar de lo editado y no hay deshacer.
    // Un clic accidental costaría el trabajo de todo un boletín, así que
    // confirma y recuerda que Guardar existe.
    document.getElementById('btn-descartar').addEventListener('click', function () {
      var seguro = window.confirm(
        '¿Descartar todo lo editado y volver al texto original?\n\n' +
        'Esto no se puede deshacer. Si quiere conservarlo, cancele y use ' +
        'primero el botón Guardar.');
      if (!seguro) return;
      Exportar.borrarBorrador();
      estado.contenido = Datos.clonar(CONTENIDO_INICIAL);
      estado.plantilla = '1';
      estado.tema = 'azul';
      pintar(); marcarGaleria(); marcarTemas();
      document.getElementById('restaurado').hidden = true;
      aviso('Borrador descartado; se recuperó el contenido original.', 'ok');
    });

    window.addEventListener('resize', ajustar);

    // Ctrl+P: volcar lo que se esté escribiendo y dejar la hoja limpia.
    window.addEventListener('beforeprint', function () {
      Editor.volcarPendiente();
      Exportar.reglaPagina(estado.plantilla);
    });

    // html2canvas viene de un CDN. Sin internet no carga: se oculta el botón
    // de PNG y el resto de la aplicación sigue funcionando.
    window.addEventListener('load', function () {
      if (!Exportar.hayHtml2canvas()) {
        document.getElementById('btn-png').hidden = true;
        var n = document.getElementById('nota-png');
        if (n) n.hidden = false;
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }

  return {
    estado: estado,
    pintar: pintar,
    ajustar: ajustar,
    aviso: aviso,
    alternarEdicion: alternarEdicion,
    cambiarPlantilla: cambiarPlantilla,
    cambiarTema: cambiarTema,
    aplicarInstantanea: aplicarInstantanea
  };
})();
