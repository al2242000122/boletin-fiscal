/* ==========================================================================
   plantillas.js — el estado vive en el objeto de datos; aquí solo se pinta.
   Cada nodo editable lleva data-campo="<ruta>" y el editor escribe de vuelta
   en esa ruta. Por eso cambiar de plantilla nunca pierde texto.

   Rutas admitidas:
     gancho                      foto
     meta.kicker | meta.mes | meta.articulo | meta.ordenamiento | meta.subtitulo
     meta.plazo.numero | meta.plazo.unidad
     pie.entrada | pie.firma | pie.correos
     sec.<id>.titulo | sec.<id>.texto | sec.<id>.entrada | sec.<id>.items.<i>
   ========================================================================== */

var Datos = (function () {

  function clonar(o) { return JSON.parse(JSON.stringify(o)); }

  function seccion(d, id) {
    for (var i = 0; i < d.secciones.length; i++) if (d.secciones[i].id === id) return d.secciones[i];
    return null;
  }

  /* Devuelve { obj, clave } para poder leer y escribir en la misma ruta. */
  function resolver(d, ruta) {
    var p = String(ruta).split('.'), obj = d, i = 0;
    if (p[0] === 'sec') { obj = seccion(d, p[1]); i = 2; }
    for (; i < p.length - 1 && obj != null; i++) obj = obj[p[i]];
    return obj == null ? null : { obj: obj, clave: p[p.length - 1] };
  }

  function leer(d, ruta) { var r = resolver(d, ruta); return r ? r.obj[r.clave] : ''; }

  /* Escribir nunca debe CREAR posiciones en una lista: si la ruta trae un
     índice fuera de rango es que venía de un nodo caduco (la lista se repintó
     entre medias) y aplicarla resucitaría una viñeta ya eliminada. */
  function escribir(d, ruta, valor) {
    var r = resolver(d, ruta);
    if (!r) return false;
    if (Array.isArray(r.obj)) {
      var i = parseInt(r.clave, 10);
      if (!(i >= 0 && i < r.obj.length)) return false;
    }
    r.obj[r.clave] = valor;
    return true;
  }

  /* Formatos de presentación: el dato se guarda íntegro, la plantilla lo muestra
     recortado. "numeral" convierte "Artículo 49 Bis" en "49 Bis" para el bloque
     grande del hero, y al guardar vuelve a anteponer "Artículo ". Ningún carácter
     del contenido se pierde. */
  var FORMATOS = {
    numeral: {
      mostrar: function (v) { return String(v).replace(/^Art[íi]culo\s+/i, ''); },
      guardar: function (v, previo) {
        return /^Art[íi]culo\s+/i.test(String(previo)) ? 'Artículo ' + v : v;
      }
    }
  };

  return {
    clonar: clonar, seccion: seccion, leer: leer, escribir: escribir, FORMATOS: FORMATOS,
    mostrar: function (d, ruta, formato) {
      var v = leer(d, ruta);
      return formato && FORMATOS[formato] ? FORMATOS[formato].mostrar(v) : v;
    }
  };
})();


var Plantillas = (function () {

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* Nodo editable ligado a una ruta del objeto de datos. */
  function campo(ruta, valor, op) {
    op = op || {};
    var tag = op.tag || 'span';
    return '<' + tag + ' class="' + (op.clase || '') + '" data-campo="' + ruta + '"' +
      (op.formato ? ' data-formato="' + op.formato + '"' : '') + '>' + esc(valor) + '</' + tag + '>';
  }

  /* Lista con viñetas: cada <li> lleva su botón de baja, la lista su botón de alta.
     El texto editable es el <span>, nunca el <li>, para que el botón no acabe
     dentro del contenido al leerlo de vuelta. */
  function lista(sec, clase) {
    var ruta = 'sec.' + sec.id + '.items';
    var unica = sec.items.length <= 1;   // no se puede borrar la última viñeta
    var h = '<ul class="lista ' + (clase || '') + '" data-lista="' + ruta + '">';
    for (var i = 0; i < sec.items.length; i++) {
      h += '<li class="it">' +
        campo(ruta + '.' + i, sec.items[i], { clase: 'tx' }) +
        '<button type="button" class="quitar-vineta solo-edicion" data-quitar="' + ruta + '.' + i + '"' +
        (unica ? ' disabled title="No se puede eliminar la única viñeta de la lista"'
               : ' title="Eliminar viñeta"') +
        ' aria-label="Eliminar viñeta">&times;</button>' +
        '</li>';
    }
    return h + '</ul>';
  }

  function botonAgregar(sec, clase) {
    return '<button type="button" class="agregar-vineta solo-edicion ' + (clase || '') +
      '" data-agregar="sec.' + sec.id + '.items">+ Agregar viñeta</button>';
  }


  /* ======================================================================
     Plantilla 1 — Ejecutivo vertical (816 × 1056, carta exacta a 96 dpi)
     ====================================================================== */
  function plantilla1(d) {
    var s = function (id) { return Datos.seccion(d, id); };
    var quees = s('quees'), puede = s('puede'), riesgos = s('riesgos'),
        prep = s('preparacion'), consejo = s('consejo'), apoyo = s('apoyo');

    function bloqueLista(sec, clase) {
      return '<div class="blk ' + (clase || '') + '">' +
        campo('sec.' + sec.id + '.titulo', sec.titulo, { tag: 'h3', clase: 'p1-h3' }) +
        campo('sec.' + sec.id + '.entrada', sec.entrada, { tag: 'p', clase: 'p1-entrada' }) +
        lista(sec, 'p1-lista') + botonAgregar(sec) +
        '</div>';
    }

    return '' +
      '<div class="p1">' +

        '<header class="p1-banda">' +
          campo('meta.kicker', d.meta.kicker, { clase: 'p1-kicker' }) +
          campo('pie.firma', d.pie.firma, { clase: 'p1-firma' }) +
        '</header>' +

        '<section class="p1-hero">' +
          '<div class="p1-art">' +
            '<span class="p1-art-etq">Artículo</span>' +
            campo('meta.articulo', Datos.mostrar(d, 'meta.articulo', 'numeral'),
                  { clase: 'p1-art-num', formato: 'numeral' }) +
            campo('meta.ordenamiento', d.meta.ordenamiento, { clase: 'p1-art-ord' }) +
          '</div>' +
          '<div class="p1-foto" data-foto title="Clic para cambiar la fotografía">' +
            '<div class="p1-velo"></div>' +
            campo('gancho', d.gancho, { tag: 'p', clase: 'p1-gancho' }) +
          '</div>' +
        '</section>' +

        '<div class="p1-cuerpo">' +

          '<div class="blk">' +
            campo('sec.quees.titulo', quees.titulo, { tag: 'h3', clase: 'p1-h3' }) +
            campo('sec.quees.texto', quees.texto, { tag: 'p', clase: 'p1-p' }) +
          '</div>' +

          bloqueLista(puede, 'p1-puede') +

          '<div class="p1-fila2">' +
            bloqueLista(riesgos, 'tono-riesgo') +
            bloqueLista(prep, 'tono-recomendacion') +
          '</div>' +

          '<div class="blk p1-consejo">' +
            campo('sec.consejo.titulo', consejo.titulo, { tag: 'h3', clase: 'p1-consejo-etq' }) +
            campo('sec.consejo.texto', consejo.texto, { tag: 'p', clase: 'p1-consejo-tx' }) +
          '</div>' +

          '<div class="blk p1-apoyo">' +
            '<div class="p1-apoyo-izq">' +
              campo('sec.apoyo.titulo', apoyo.titulo, { tag: 'h3', clase: 'p1-apoyo-etq' }) +
              campo('sec.apoyo.entrada', apoyo.entrada, { tag: 'p', clase: 'p1-apoyo-entrada' }) +
            '</div>' +
            '<div class="p1-apoyo-der">' +
              lista(apoyo, 'p1-apoyo-lista') +
            '</div>' +
            botonAgregar(apoyo, 'dentro') +
          '</div>' +

        '</div>' +

        '<footer class="p1-pie">' +
          '<div class="p1-pie-izq">' +
            campo('pie.entrada', d.pie.entrada, { tag: 'p', clase: 'p1-pie-entrada' }) +
            campo('pie.firma', d.pie.firma, { tag: 'p', clase: 'p1-pie-firma' }) +
          '</div>' +
          campo('pie.correos', d.pie.correos, { tag: 'p', clase: 'p1-pie-correos' }) +
        '</footer>' +

      '</div>';
  }


  /* ======================================================================
     Plantilla 2 — Procedimiento horizontal (1400 × 1000)
     ====================================================================== */
  function plantilla2(d) {
    var s = function (id) { return Datos.seccion(d, id); };
    var quees = s('quees'), puede = s('puede'), riesgos = s('riesgos'),
        prep = s('preparacion'), consejo = s('consejo'), apoyo = s('apoyo');

    /* Las tarjetas se numeran 01…N. La numeración es cromo de la plantilla,
       no contenido: si se agrega una viñeta aparece la 06 sin tocar el dato. */
    function tarjetas(sec) {
      var ruta = 'sec.' + sec.id + '.items';
      var unica = sec.items.length <= 1;
      var h = '<div class="p2-tarjetas" data-lista="' + ruta + '">';
      for (var i = 0; i < sec.items.length; i++) {
        h += '<div class="p2-tarjeta it">' +
          '<span class="p2-num">' + (i < 9 ? '0' : '') + (i + 1) + '</span>' +
          campo(ruta + '.' + i, sec.items[i], { clase: 'tx' }) +
          '<button type="button" class="quitar-vineta solo-edicion" data-quitar="' + ruta + '.' + i + '"' +
          (unica ? ' disabled title="No se puede eliminar la única viñeta de la lista"'
                 : ' title="Eliminar viñeta"') +
          ' aria-label="Eliminar viñeta">&times;</button>' +
          '</div>';
      }
      return h + '</div>';
    }

    function columna(sec, clase) {
      return '<div class="blk p2-col ' + clase + '">' +
        campo('sec.' + sec.id + '.titulo', sec.titulo, { tag: 'h3', clase: 'p2-h3' }) +
        campo('sec.' + sec.id + '.entrada', sec.entrada, { tag: 'p', clase: 'p2-entrada' }) +
        lista(sec, 'p2-lista') + botonAgregar(sec) +
        '</div>';
    }

    return '' +
      '<div class="p2">' +

        '<header class="p2-cabeza">' +
          '<div class="p2-cabeza-fila">' +
            '<div class="p2-titulo">' +
              campo('meta.kicker', d.meta.kicker, { clase: 'p2-kicker' }) +
              campo('meta.articulo', d.meta.articulo, { tag: 'h1', clase: 'p2-art' }) +
              campo('meta.ordenamiento', d.meta.ordenamiento, { clase: 'p2-ord' }) +
            '</div>' +
            '<div class="p2-plazo">' +
              campo('meta.plazo.numero', d.meta.plazo.numero, { clase: 'p2-plazo-num' }) +
              campo('meta.plazo.unidad', d.meta.plazo.unidad, { clase: 'p2-plazo-uni' }) +
            '</div>' +
          '</div>' +
          /* El riel representa ÚNICAMENTE el plazo de 24 días hábiles del texto.
             No hay etapas ni fases: inventarlas sería inventar procedimiento. */
          '<div class="p2-riel">' +
            '<span class="p2-riel-etq">Inicio del procedimiento</span>' +
            '<span class="p2-riel-barra" aria-hidden="true"></span>' +
            '<span class="p2-riel-etq">Conclusión</span>' +
          '</div>' +
        '</header>' +

        '<section class="p2-gancho">' +
          campo('gancho', d.gancho, { tag: 'p', clase: 'p2-gancho-tx' }) +
        '</section>' +

        '<section class="blk p2-puede">' +
          '<div class="p2-rotulo">' +
            campo('sec.puede.titulo', puede.titulo, { tag: 'h3', clase: 'p2-h3' }) +
            campo('sec.puede.entrada', puede.entrada, { tag: 'p', clase: 'p2-entrada' }) +
          '</div>' +
          tarjetas(puede) +
          botonAgregar(puede, 'dentro') +
        '</section>' +

        '<section class="p2-cuerpo">' +
          columna(riesgos, 'tono-riesgo') +
          columna(prep, 'tono-recomendacion') +
          '<aside class="p2-aside">' +
            '<div class="p2-aside-blq">' +
              campo('sec.quees.titulo', quees.titulo, { tag: 'h3', clase: 'p2-h3' }) +
              campo('sec.quees.texto', quees.texto, { tag: 'p', clase: 'p2-p' }) +
            '</div>' +
            '<div class="p2-aside-blq p2-aside-consejo">' +
              campo('sec.consejo.titulo', consejo.titulo, { tag: 'h3', clase: 'p2-h3' }) +
              campo('sec.consejo.texto', consejo.texto, { tag: 'p', clase: 'p2-consejo-tx' }) +
            '</div>' +
          '</aside>' +
        '</section>' +

        '<footer class="blk p2-pie">' +
          '<div class="p2-pie-etq">' +
            campo('sec.apoyo.titulo', apoyo.titulo, { tag: 'h3', clase: 'p2-h3' }) +
            campo('sec.apoyo.entrada', apoyo.entrada, { tag: 'p', clase: 'p2-entrada' }) +
          '</div>' +
          lista(apoyo, 'p2-apoyo-lista') +
          botonAgregar(apoyo, 'dentro') +
          '<div class="p2-contacto">' +
            campo('pie.entrada', d.pie.entrada, { tag: 'p', clase: 'p2-pie-entrada' }) +
            campo('pie.firma', d.pie.firma, { tag: 'p', clase: 'p2-pie-firma' }) +
            campo('pie.correos', d.pie.correos, { tag: 'p', clase: 'p2-pie-correos' }) +
          '</div>' +
        '</footer>' +

      '</div>';
  }


  /* ======================================================================
     Plantilla 3 — Editorial (816 × 1056). Sin fotografía, serif, dos columnas.
     Es la que mejor aguanta volumen de texto.
     ====================================================================== */
  function plantilla3(d) {
    var s = function (id) { return Datos.seccion(d, id); };

    /* Las 6 secciones se pintan en el orden del objeto y el navegador las
       reparte entre las dos columnas. No hay listas de secciones codificadas
       a mano: lo que hay en los datos es lo que sale. */
    function bloque(sec) {
      var clase = 'p3-blk blk' +
        (sec.tono ? ' tono-' + sec.tono : '') +
        (sec.destacado ? ' p3-consejo' : '') +
        (sec.id === 'puede' ? ' p3-numerada' : '');
      var h = '<section class="' + clase + '">' +
        campo('sec.' + sec.id + '.titulo', sec.titulo, { tag: 'h3', clase: 'p3-h3' });
      if (sec.entrada)
        h += campo('sec.' + sec.id + '.entrada', sec.entrada, { tag: 'p', clase: 'p3-entrada' });
      if (sec.tipo === 'parrafo')
        h += campo('sec.' + sec.id + '.texto', sec.texto, { tag: 'p', clase: 'p3-p' });
      else
        h += lista(sec, 'p3-lista') + botonAgregar(sec);
      return h + '</section>';
    }

    var bloques = '';
    for (var i = 0; i < d.secciones.length; i++) bloques += bloque(d.secciones[i]);

    return '' +
      '<div class="p3">' +

        '<header class="p3-cabeza">' +
          '<span class="p3-masthead">Boletín fiscal</span>' +
          '<span class="p3-cabeza-der">' +
            campo('meta.mes', d.meta.mes, { clase: 'p3-mes' }) +
            '<span class="p3-sep"> · </span>' +
            campo('pie.firma', d.pie.firma, { clase: 'p3-firma' }) +
          '</span>' +
        '</header>' +

        '<div class="p3-titular">' +
          campo('meta.ordenamiento', d.meta.ordenamiento, { clase: 'p3-kicker' }) +
          campo('meta.articulo', d.meta.articulo, { tag: 'h1', clase: 'p3-art' }) +
          campo('meta.subtitulo', d.meta.subtitulo, { clase: 'p3-sub' }) +
        '</div>' +

        '<div class="p3-destacado">' +
          campo('gancho', d.gancho, { tag: 'p', clase: 'p3-gancho' }) +
        '</div>' +

        '<div class="p3-cuerpo">' + bloques + '</div>' +

        '<footer class="p3-pie">' +
          '<div class="p3-pie-izq">' +
            campo('pie.entrada', d.pie.entrada, { tag: 'p', clase: 'p3-pie-entrada' }) +
            campo('pie.firma', d.pie.firma, { tag: 'p', clase: 'p3-pie-firma' }) +
          '</div>' +
          campo('pie.correos', d.pie.correos, { tag: 'p', clase: 'p3-pie-correos' }) +
        '</footer>' +

      '</div>';
  }


  /* ======================================================================
     Plantilla 4 — Tablero (1400 × 640). Banda ancha para el cuerpo del correo.
     ====================================================================== */
  function plantilla4(d) {
    var s = function (id) { return Datos.seccion(d, id); };
    var quees = s('quees'), puede = s('puede'), riesgos = s('riesgos'),
        prep = s('preparacion'), consejo = s('consejo'), apoyo = s('apoyo');

    function celdaLista(sec, num, clase) {
      return '<div class="blk p4-celda p4-celda-lista ' + (clase || '') + '">' +
        '<span class="p4-num">' + num + '</span>' +
        campo('sec.' + sec.id + '.titulo', sec.titulo, { tag: 'h3', clase: 'p4-h3' }) +
        campo('sec.' + sec.id + '.entrada', sec.entrada, { tag: 'p', clase: 'p4-entrada' }) +
        lista(sec, 'p4-lista') + botonAgregar(sec, 'dentro') +
        '</div>';
    }

    return '' +
      '<div class="p4">' +

        '<aside class="p4-riel">' +
          '<div class="p4-foto" data-foto title="Clic para cambiar la fotografía">' +
            '<div class="p4-velo"></div>' +
          '</div>' +
          '<div class="p4-riel-tx">' +
            campo('meta.kicker', d.meta.kicker, { clase: 'p4-kicker' }) +
            campo('meta.articulo', Datos.mostrar(d, 'meta.articulo', 'numeral'),
                  { clase: 'p4-art', formato: 'numeral' }) +
            campo('meta.ordenamiento', d.meta.ordenamiento, { clase: 'p4-ord' }) +
            campo('gancho', d.gancho, { tag: 'p', clase: 'p4-gancho' }) +
          '</div>' +
          '<div class="p4-consejo">' +
            campo('sec.consejo.titulo', consejo.titulo, { tag: 'h3', clase: 'p4-consejo-etq' }) +
            campo('sec.consejo.texto', consejo.texto, { tag: 'p', clase: 'p4-consejo-tx' }) +
          '</div>' +
          '<div class="p4-contacto">' +
            campo('pie.entrada', d.pie.entrada, { tag: 'p', clase: 'p4-pie-entrada' }) +
            campo('pie.firma', d.pie.firma, { tag: 'p', clase: 'p4-pie-firma' }) +
            campo('pie.correos', d.pie.correos, { tag: 'p', clase: 'p4-pie-correos' }) +
          '</div>' +
        '</aside>' +

        '<div class="p4-rejilla">' +
          /* Fila 1 — a las tres columnas, en fila: rótulo a la izquierda y
             párrafo al lado. La celda hereda flex-direction:column de la clase
             base, así que la fila 1 lo sobrescribe explícitamente (§10.5). */
          '<div class="blk p4-celda p4-fila1">' +
            '<div class="p4-fila1-etq">' +
              '<span class="p4-num">01</span>' +
              campo('sec.quees.titulo', quees.titulo, { tag: 'h3', clase: 'p4-h3' }) +
            '</div>' +
            campo('sec.quees.texto', quees.texto, { tag: 'p', clase: 'p4-p' }) +
          '</div>' +

          celdaLista(puede, '02', '') +
          celdaLista(riesgos, '03', 'tono-riesgo') +
          celdaLista(prep, '04', 'tono-recomendacion') +

          '<div class="blk p4-fila3">' +
            '<div class="p4-fila3-etq">' +
              '<span class="p4-num p4-num-claro">05</span>' +
              campo('sec.apoyo.titulo', apoyo.titulo, { tag: 'h3', clase: 'p4-h3' }) +
              campo('sec.apoyo.entrada', apoyo.entrada, { tag: 'p', clase: 'p4-entrada' }) +
            '</div>' +
            lista(apoyo, 'p4-apoyo-lista') +
            botonAgregar(apoyo, 'dentro') +
          '</div>' +
        '</div>' +

      '</div>';
  }


  var REGISTRO = {
    '1': { nombre: 'Ejecutivo vertical',       ancho: 816,  alto: 1056, foto: true,
           orientacion: 'vertical',   render: plantilla1 },
    '2': { nombre: 'Procedimiento horizontal', ancho: 1400, alto: 1000, foto: false,
           orientacion: 'horizontal', render: plantilla2 },
    '3': { nombre: 'Editorial',                ancho: 816,  alto: 1056, foto: false,
           orientacion: 'vertical',   render: plantilla3 },
    '4': { nombre: 'Tablero',                  ancho: 1400, alto: 640,  foto: true,
           orientacion: 'banda',      render: plantilla4 }
  };

  return {
    registro: REGISTRO,
    esc: esc,
    campo: campo,
    lista: lista,
    botonAgregar: botonAgregar,
    obtener: function (id) { return REGISTRO[String(id)]; },
    listar: function () { return Object.keys(REGISTRO); }
  };
})();
