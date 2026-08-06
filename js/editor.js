/* ==========================================================================
   editor.js — edición in-place sobre el diseño.
   Todo lo que se escribe acaba en el objeto de datos: la plantilla solo pinta.
   ========================================================================== */

var Editor = (function () {

  var ctx = null;          // { hoja, datos(), repintar(), cambio() }
  var temporizador = null;
  var pendiente = null;

  /* ---- lectura del nodo editable ----------------------------------------
     textContent, NO innerText: innerText devuelve el texto tal como se ve, ya
     pasado por text-transform. Los h3 van en versalitas por CSS, as\u00ed que con
     innerText el modelo acabar\u00eda guardando "\u00bfQU\u00c9 ES EL ART\u00cdCULO 49 BIS?" y ese
     destrozo viajar\u00eda a las dem\u00e1s plantillas. Como Enter est\u00e1 bloqueado, no hay
     saltos de l\u00ednea que innerText tuviera que resolver. */
  function valorDe(nodo) {
    return String(nodo.textContent)
      .replace(/\u00a0/g, ' ').replace(/\s*\r?\n\s*/g, ' ').trim();
  }

  function guardarNodo(nodo) {
    if (!nodo || !nodo.getAttribute) return;
    // Un nodo ya desprendido del documento tiene una ruta caduca: si la lista
    // se repint\u00f3, su \u00edndice puede apuntar a un elemento que ya no existe.
    if (nodo.isConnected === false) return;
    var ruta = nodo.getAttribute('data-campo');
    if (!ruta) return;
    var formato = nodo.getAttribute('data-formato');
    var texto = valorDe(nodo);
    var previo = Datos.leer(ctx.datos(), ruta);
    var valor = (formato && Datos.FORMATOS[formato])
      ? Datos.FORMATOS[formato].guardar(texto, previo)
      : texto;
    Datos.escribir(ctx.datos(), ruta, valor);
    if (ctx.cambio) ctx.cambio();
  }

  /* Un mismo campo puede aparecer en dos sitios (p. ej. la firma sale en la
     banda superior y en el pie). Al soltar el foco se igualan. */
  function sincronizarGemelos(nodo) {
    var ruta = nodo.getAttribute('data-campo');
    if (!ruta) return;
    var d = ctx.datos();
    var iguales = ctx.hoja.querySelectorAll('[data-campo="' + ruta + '"]');
    for (var i = 0; i < iguales.length; i++) {
      if (iguales[i] === nodo) continue;
      var f = iguales[i].getAttribute('data-formato');
      iguales[i].textContent = Datos.mostrar(d, ruta, f);
    }
  }

  /* Vuelca al modelo lo que haya en vuelo ANTES de tocar la estructura de una
     lista. Además suelta el foco: si un campo sigue enfocado cuando se repinta,
     su blur llegaría después del repintado, con un índice que ya no existe. */
  function volcarPendiente() {
    if (temporizador) { clearTimeout(temporizador); temporizador = null; }
    if (pendiente) { guardarNodo(pendiente); pendiente = null; }
    var activo = document.activeElement;
    if (activo && activo !== document.body && ctx.hoja.contains(activo) &&
        activo.getAttribute && activo.getAttribute('data-campo')) {
      guardarNodo(activo);
      activo.blur();
    }
  }

  /* ---- alta y baja de viñetas ------------------------------------------- */
  function rutaLista(rutaItem) {
    var m = String(rutaItem).match(/^(.*)\.(\d+)$/);
    return m ? { lista: m[1], indice: parseInt(m[2], 10) } : null;
  }

  function agregarVineta(ruta) {
    volcarPendiente();
    var arreglo = Datos.leer(ctx.datos(), ruta);
    if (!arreglo || !arreglo.push) return;
    arreglo.push('Nueva viñeta');
    ctx.repintar();
    if (ctx.cambio) ctx.cambio();
    var nuevo = ctx.hoja.querySelector('[data-campo="' + ruta + '.' + (arreglo.length - 1) + '"]');
    if (nuevo) { nuevo.focus(); seleccionarTodo(nuevo); }
  }

  function quitarVineta(rutaItem) {
    volcarPendiente();
    var r = rutaLista(rutaItem);
    if (!r) return;
    var arreglo = Datos.leer(ctx.datos(), r.lista);
    if (!arreglo || arreglo.length <= 1) return;   // nunca dejar la lista vacía
    arreglo.splice(r.indice, 1);
    ctx.repintar();
    if (ctx.cambio) ctx.cambio();
  }

  function seleccionarTodo(nodo) {
    try {
      var rango = document.createRange();
      rango.selectNodeContents(nodo);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(rango);
    } catch (e) { /* sin selección: no es crítico */ }
  }

  /* ---- fotografía -------------------------------------------------------- */
  function cambiarFoto() {
    var inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = 'image/*';
    inp.addEventListener('change', function () {
      var archivo = inp.files && inp.files[0];
      if (!archivo) return;
      var lector = new FileReader();
      lector.onload = function () {
        ctx.datos().foto = lector.result;
        ctx.repintar();
        if (ctx.cambio) ctx.cambio();
      };
      lector.readAsDataURL(archivo);
    });
    inp.click();
  }

  /* ---- modo edición ------------------------------------------------------ */
  function aplicarModo(editando) {
    ctx.hoja.classList.toggle('editando', !!editando);
    var campos = ctx.hoja.querySelectorAll('[data-campo]');
    for (var i = 0; i < campos.length; i++) {
      if (editando) {
        campos[i].setAttribute('contenteditable', 'true');
        campos[i].setAttribute('spellcheck', 'false');
      } else {
        campos[i].removeAttribute('contenteditable');
        campos[i].removeAttribute('spellcheck');
      }
    }
  }

  /* ---- cableado ---------------------------------------------------------- */
  function conectar() {
    var hoja = ctx.hoja;

    hoja.addEventListener('input', function (e) {
      var n = e.target;
      if (!n.getAttribute || !n.getAttribute('data-campo')) return;
      pendiente = n;
      if (temporizador) clearTimeout(temporizador);
      temporizador = setTimeout(function () { temporizador = null; guardarNodo(n); pendiente = null; }, 300);
    });

    // blur no burbujea: hay que escucharlo en captura.
    hoja.addEventListener('blur', function (e) {
      var n = e.target;
      if (!n.getAttribute || !n.getAttribute('data-campo')) return;
      if (pendiente === n) { if (temporizador) clearTimeout(temporizador); temporizador = null; pendiente = null; }
      guardarNodo(n);
      sincronizarGemelos(n);
    }, true);

    // Pegar siempre como texto plano: Word inyecta <span style="font-family:Calibri">
    // y rompe el diseño.
    hoja.addEventListener('paste', function (e) {
      var n = e.target;
      if (!n.getAttribute || !n.getAttribute('data-campo')) return;
      e.preventDefault();
      var dt = e.clipboardData || window.clipboardData;
      var texto = dt ? dt.getData('text/plain') : '';
      texto = String(texto).replace(/\s*\r?\n\s*/g, ' ');
      document.execCommand('insertText', false, texto);
    });

    hoja.addEventListener('drop', function (e) {
      var n = e.target;
      if (n.getAttribute && n.getAttribute('data-campo')) e.preventDefault();
    });

    hoja.addEventListener('keydown', function (e) {
      var n = e.target;
      if (!n.getAttribute || !n.getAttribute('data-campo')) return;
      // Los campos son cadenas de texto: ni saltos de línea ni negritas sueltas.
      if (e.key === 'Enter') { e.preventDefault(); n.blur(); return; }
      if ((e.ctrlKey || e.metaKey) && /^[biu]$/i.test(e.key)) e.preventDefault();
    });

    hoja.addEventListener('click', function (e) {
      var quitar = e.target.closest && e.target.closest('[data-quitar]');
      if (quitar) { e.preventDefault(); quitarVineta(quitar.getAttribute('data-quitar')); return; }
      var agregar = e.target.closest && e.target.closest('[data-agregar]');
      if (agregar) { e.preventDefault(); agregarVineta(agregar.getAttribute('data-agregar')); return; }
      if (!hoja.classList.contains('editando')) return;
      var foto = e.target.closest && e.target.closest('[data-foto]');
      if (foto && !(e.target.getAttribute && e.target.getAttribute('data-campo'))) cambiarFoto();
    });
  }

  return {
    iniciar: function (contexto) { ctx = contexto; conectar(); },
    aplicarModo: aplicarModo,
    volcarPendiente: volcarPendiente,
    cambiarFoto: cambiarFoto
  };
})();
