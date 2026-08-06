# Generador de Boletines Fiscales — Especificación técnica

> Documento de entrada para Claude Code. Contiene todo lo necesario para construir el proyecto sin contexto adicional.

---

## 1. Contexto

Un despacho contable (**International Support Services, S.C.**) publica boletines fiscales mensuales para sus clientes. Hoy los arma a mano en Word/PowerPoint y el resultado es inconsistente y lento de producir.

Se necesita una **página web local** donde el equipo del despacho pueda:

1. Elegir entre 4 plantillas de diseño ya definidas.
2. Editar todos los textos directamente sobre el diseño.
3. Exportar el resultado como PNG (para pegar en el correo) o PDF (para adjuntar).
4. Guardar el boletín y retomarlo el mes siguiente.

El contenido del mes de referencia (Agosto 2026, Artículo 49 Bis del CFF) está transcrito íntegro en la §5 y debe venir precargado como contenido por defecto.

### Audiencia final del boletín

Dos lectores en la misma hoja:

- **Dueños de empresa** — leen el gancho, el dato duro (24 días hábiles) y "qué hago hoy". Escanean, no leen.
- **Contadores** — quieren fundamento legal, precisión terminológica y el detalle completo.

La jerarquía visual debe servir primero al dueño; el detalle va abajo.

---

## 2. Usuarios y flujo

**Usuario**: personal administrativo del despacho. Perfil Office, sin conocimientos técnicos. Trabaja en Windows con Chrome o Edge.

**Flujo esperado**:

```
Abrir index.html
  → Elegir plantilla (galería con miniaturas)
  → Activar edición
  → Escribir sobre el diseño / agregar o quitar viñetas / cambiar foto
  → (opcional) Cambiar tema de color
  → Exportar PNG · Imprimir a PDF · Guardar
```

---

## 3. Alcance

### Dentro

- 4 plantillas, seleccionables y conmutables **sin perder el contenido escrito**.
- Edición WYSIWYG in-place de todo el texto.
- Agregar / eliminar viñetas de cualquier lista.
- Sustituir la fotografía (para las plantillas que la usan).
- 3 temas de color aplicables a cualquier plantilla.
- Exportación PNG, impresión a PDF, guardado y carga.
- Todo el copy de la interfaz en español de México.

### Fuera

- Backend, base de datos, autenticación, multiusuario.
- Editor de layout (mover o redimensionar bloques). Las plantillas son fijas.
- Envío de correo desde la app.
- Soporte para Internet Explorer o Safari < 16.

---

## 4. Stack y estructura

**Sin framework y sin paso de compilación obligatorio.** La app debe correr abriendo `index.html` con doble clic (protocolo `file://`), porque el despacho no va a levantar un servidor.

- HTML + CSS + JavaScript vanilla (ES modules con `type="module"` funcionan en `file://` solo si se sirve por HTTP; **usar scripts clásicos o un solo bundle sin `import`** para garantizar `file://`).
- Única dependencia externa: `html2canvas` 1.4.1 desde cdnjs, con degradación elegante si no hay internet (si falla la carga, ocultar el botón PNG y dejar solo Imprimir/PDF).
- Sin Tailwind, sin build de CSS.

```
boletin-fiscal/
├── index.html              # shell: barra de herramientas + galería + contenedor de la hoja
├── css/
│   ├── app.css             # UI (barra, galería, modales, estados de edición)
│   ├── tokens.css          # variables CSS: paleta, tipografía, temas
│   ├── plantilla-1.css
│   ├── plantilla-2.css
│   ├── plantilla-3.css
│   ├── plantilla-4.css
│   └── print.css           # reglas @media print y @page por plantilla
├── js/
│   ├── contenido.js        # OBJETO de datos con el texto del boletín (§5)
│   ├── plantillas.js       # funciones que renderizan cada plantilla a partir del objeto
│   ├── editor.js           # contenteditable, alta/baja de viñetas, cambio de foto
│   ├── exportar.js         # PNG, impresión, guardar/cargar
│   └── app.js              # arranque, estado, cableado de eventos
├── assets/
│   └── torre.jpg           # foto por defecto (adjunta a este documento)
└── build.mjs               # OPCIONAL: inlinea todo en dist/boletin.html (un solo archivo portable)
```

`build.mjs` (Node, sin dependencias) concatena CSS y JS y convierte `torre.jpg` a base64 para producir un `dist/boletin.html` autocontenido que el despacho pueda mandar por correo o guardar en Drive. Es un extra, no bloquea la entrega.

---

## 5. Modelo de contenido

Todo el texto vive en un solo objeto. Las plantillas se alimentan de él; editar en pantalla actualiza el objeto.

> ⚠️ **Este texto es transcripción del documento Word del cliente. No parafrasear, no "mejorar", no inventar secciones.** Solo se corrigieron dos erratas del original: `insuser,mx` → `insuser.mx` y "sobre está publicación" → "esta".

```js
// js/contenido.js
const CONTENIDO_INICIAL = {
  meta: {
    kicker: "Boletín fiscal · Agosto 2026",
    mes: "Agosto 2026",
    articulo: "Artículo 49 Bis",
    ordenamiento: "del Código Fiscal de la Federación",
    subtitulo: "Visita domiciliaria para verificar la materialidad de las operaciones",
    plazo: { numero: "24", unidad: "días hábiles" }
  },

  gancho: "Si el SAT visitara hoy su empresa, ¿podría demostrar en pocos días que cada operación facturada ocurrió realmente, con evidencia de entrega, trazabilidad de pago, respaldo logístico y capacidad operativa?",

  secciones: [
    {
      id: "quees",
      titulo: "¿Qué es el artículo 49 Bis?",
      tipo: "parrafo",
      texto: "El artículo 49 Bis del Código Fiscal de la Federación establece un procedimiento de visita domiciliaria que puede iniciar y concluir en 24 días hábiles, que permite al SAT verificar a aquellos contribuyentes respecto de los cuales existan indicios de que emiten CFDI que amparan operaciones inexistentes, simuladas o sin sustancia económica."
    },
    {
      id: "puede",
      titulo: "¿Qué puede hacer el SAT?",
      tipo: "lista",
      entrada: "Durante este procedimiento la autoridad podrá:",
      items: [
        "Realizar visitas en el domicilio fiscal, sucursales o establecimientos.",
        "Revisar la existencia material del negocio.",
        "Verificar infraestructura, activos, personal y capacidad operativa.",
        "Solicitar contratos, registros contables, estados de cuenta y demás documentación que acredite la realidad de las operaciones.",
        "Suspender temporalmente la emisión de CFDI durante el procedimiento, cuando así lo establezca la orden de visita."
      ]
    },
    {
      id: "riesgos",
      titulo: "¿Cuáles son los riesgos?",
      tipo: "lista",
      tono: "riesgo",
      entrada: "Una revisión bajo este procedimiento puede generar:",
      items: [
        "Suspensión de la emisión de comprobantes fiscales.",
        "Interrupción de la operación comercial.",
        "Observaciones fiscales que deriven en créditos fiscales.",
        "Riesgos para clientes y proveedores relacionados con las operaciones observadas.",
        "Posibles responsabilidades administrativas o penales en los casos previstos por la ley."
      ]
    },
    {
      id: "preparacion",
      titulo: "¿Cómo prepararse?",
      tipo: "lista",
      tono: "recomendacion",
      entrada: "Se recomienda que las empresas:",
      items: [
        "Mantengan expedientes completos de cada operación.",
        "Conserven evidencia documental de la prestación de servicios o entrega de bienes.",
        "Verifiquen la congruencia entre contratos, CFDI, pagos y registros contables.",
        "Implementen controles internos para validar la materialidad de sus operaciones.",
        "Atiendan oportunamente cualquier requerimiento de la autoridad."
      ]
    },
    {
      id: "consejo",
      titulo: "Nuestro consejo",
      tipo: "parrafo",
      destacado: true,
      texto: "La mejor defensa frente a una auditoría exprés no se construye durante la visita. Se construye antes, con expedientes de materialidad ordenados, procesos documentales sólidos y una relación de confianza con un equipo fiscal que conozca a fondo la operación del negocio."
    },
    {
      id: "apoyo",
      titulo: "¿Necesita apoyo?",
      tipo: "lista",
      entrada: "Nuestro equipo puede ayudarle a:",
      items: [
        "Revisar el cumplimiento de sus obligaciones fiscales.",
        "Evaluar la materialidad de sus operaciones.",
        "Preparar expedientes de soporte documental.",
        "Brindar acompañamiento durante actos de fiscalización del SAT."
      ]
    }
  ],

  pie: {
    entrada: "Para más información o comentarios sobre esta publicación contacte a:",
    firma: "International Support Services, S.C.",
    correos: "jmdm@insuser.mx · dff@insuser.mx"
  },

  foto: "assets/torre.jpg"   // se sustituye por un data:URL cuando el usuario sube otra
};
```

**Regla dura**: las 4 plantillas deben mostrar las 6 secciones completas, el gancho y el pie. Ninguna plantilla puede omitir contenido; si no cabe, se ajusta la tipografía, no se recorta el texto.

---

## 6. Tokens de diseño

```css
/* css/tokens.css */
:root{
  /* Paleta base — tema "azul" */
  --navy:      #0A2540;   /* fondos oscuros, títulos */
  --navy-2:    #123A63;   /* bandas secundarias */
  --acc:       #1D6FA5;   /* acento, viñetas, reglas */
  --soft:      #E4EDF5;   /* cajas suaves */
  --ink:       #17232F;   /* texto principal */
  --mut:       #5D6E7E;   /* texto secundario */
  --rule:      #DCE4EC;   /* hairlines */
  --bad:       #B03A48;   /* riesgos */
  --ok:        #1E8C74;   /* recomendaciones */

  --sans: "Inter","Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --serif: Georgia,"Times New Roman",serif;
}
```

**Tipografía**: usar la pila de sistema. No cargar Google Fonts — el despacho trabaja con internet intermitente y `file://`. Si se quiere Inter, empaquetar el `.woff2` en `assets/` y declararlo con `@font-face` local.

### Temas

Se aplican con `data-tema` en el contenedor de la hoja. Son **solo sustitución de variables**; no cambian el layout.

| Tema | `--navy` | `--navy-2` | `--acc` | `--soft` | Uso |
|---|---|---|---|---|---|
| `azul` (default) | `#0A2540` | `#123A63` | `#1D6FA5` | `#E4EDF5` | Corporativo, imprime bien |
| `verde` | `#12352C` | `#1B4A3D` | `#C6742A` | `#EFE9DC` | Diferenciación de marca |
| `nocturno` | `#0E1116` | `#161B22` | `#C9A227` | `#1A1F26` | Solo envío digital; consume tóner |

En `nocturno` hay que invertir también `--ink` (`#E7E9EC`), `--mut` (`#8A9199`) y `--rule` (`#1E242C`), y el fondo de la hoja pasa a `--navy`. Resolverlo con un bloque `[data-tema="nocturno"]` completo, no con parches.

---

## 7. Especificación de las plantillas

Todas se renderizan dentro de un contenedor `.hoja` con **dimensiones fijas en px** (no responsivo). En pantallas chicas, la hoja se escala con `transform: scale()` calculado en JS, nunca reflowing el contenido: lo que se ve es lo que se exporta.

| # | Nombre | Dimensiones | Orientación | Uso previsto |
|---|---|---|---|---|
| 1 | Ejecutivo vertical | 816 × 1056 | Vertical (carta) | PDF adjunto, impresión |
| 2 | Procedimiento horizontal | 1400 × 1000 | Horizontal | Presentación, adjunto |
| 3 | Editorial | 816 × 1056 | Vertical (carta) | Publicación sobria del despacho |
| 4 | Tablero | 1400 × 640 | Banda ancha | Cuerpo del correo, sin zoom |

816 × 1056 px = 8.5 × 11 in exactos a 96 dpi. No cambiar esos números.

---

### 7.1 Plantilla 1 — Ejecutivo vertical

Columna única con bloques apilados. Estructura de arriba a abajo:

1. **Banda superior** (`--navy`, alto ~46px): kicker a la izquierda, firma a la derecha.
2. **Hero** en dos columnas:
   - Izquierda (212px, `--navy-2`): etiqueta "Artículo", `49 Bis` a 60px, ordenamiento debajo.
   - Derecha (resto): foto de fondo con degradado `linear-gradient(90deg, rgba(10,37,64,.95), rgba(10,37,64,.78))` encima; sobre él, el gancho a 16px/600.
3. **Cuerpo** (`padding: 22px 40px`, `display:flex; flex-direction:column; gap:34px; flex:1`):
   - `¿Qué es el artículo 49 Bis?` — párrafo.
   - `¿Qué puede hacer el SAT?` — lista de 5 en **rejilla de 2 columnas con flujo por columna**: `grid-template-columns:1fr 1fr; grid-template-rows:repeat(3,auto); grid-auto-flow:column`. Sin esto el orden de lectura queda 1-3-5 / 2-4 y confunde.
   - Fila de dos columnas: riesgos (viñeta rombo `--bad`) y preparación (palomita `--ok`).
   - **Nuestro consejo** — banda `--navy-2` con `margin-top:auto`.
   - **¿Necesita apoyo?** — banda `--soft` a ancho completo (`margin: 0 -40px`), lista de 4 en 2 columnas.
4. **Pie** (`--navy`): entrada + firma a la izquierda, correos a la derecha.

Tamaños: h3 14px versalitas con `letter-spacing:.07em` en `--acc`; entradas de lista 12px itálica `--mut`; ítems 12.8px; párrafos 13.2px.

---

### 7.2 Plantilla 2 — Procedimiento horizontal

1. **Encabezado**: bloque de título a la izquierda; a la derecha el numeral `24` a 58px con "DÍAS HÁBILES" debajo.
2. **Riel de plazo**: `INICIO DEL PROCEDIMIENTO ——— CONCLUSIÓN` con una barra de 6px en degradado `--acc → --navy`. Cierra con `border-bottom: 3px solid var(--navy)`.
   > La barra representa **únicamente** el plazo de 24 días hábiles que menciona el texto. No inventar etapas ni numerarlas como fases del procedimiento.
3. **Banda del gancho**: fondo `--navy`, texto blanco 17px/600.
4. **Cinco tarjetas** con los ítems de `¿Qué puede hacer el SAT?`, numeradas `01`–`05` en serif 26px `--acc`, separadas por `border-right: 1px solid var(--rule)`. Encima, un rótulo: `¿QUÉ PUEDE HACER EL SAT?` + la entrada en itálica.
5. **Cuerpo en tres columnas**:
   - Riesgos (5) y Preparación (5): listas que **rellenan la altura** con `ul{flex:1}` + `li{flex:1; display:flex; align-items:center; border-bottom:1px solid var(--rule)}`, último sin borde. Las viñetas absolutas deben ir a `top:50%` con corrección (`margin-top:-4px` o `translateY(-50%)`), no a `top:5px`.
   - Aside de 340px con fondo `--soft`: `¿Qué es el 49 Bis?` arriba y `Nuestro consejo` abajo (`margin-top:auto` en el rótulo).
6. **Pie** `--navy-2`: `¿Necesita apoyo?` en rejilla de 2 columnas a la izquierda, bloque de contacto a la derecha separado por `border-left`.

---

### 7.3 Plantilla 3 — Editorial

Registro sobrio, **sin fotografía**, tipografía serif. Es la que mejor aguanta volumen de texto.

1. **Cabecera**: `BOLETÍN FISCAL` en serif 26px con `letter-spacing:.18em`, fecha y firma a la derecha, `border-bottom: 3px double var(--navy)`.
2. **Titular**: kicker `CÓDIGO FISCAL DE LA FEDERACIÓN` en sans; `Artículo 49 Bis` en serif 34px peso normal; subtítulo en itálica `--mut`.
3. **Destacado**: el gancho en caja `--soft` con `border-left: 4px solid var(--acc)`, itálica 15.5px.
4. **Cuerpo a dos columnas**: `column-count:2; column-gap:34px; column-rule:1px solid var(--rule)`. Cada sección es un `.blk` con `break-inside:avoid`. Los h3 van en sans versalitas con hairline inferior — el contraste sans/serif es lo que sostiene la dirección.
   - `Nuestro consejo` se invierte: bloque `--navy` con texto claro, alineado a la izquierda (**no justificado**: en itálica genera ríos horribles).
5. **Pie** `--navy-2` a sangre (`margin: 0 -52px`).

Cuerpo justificado a 13.2px/1.58; ítems 12.9px.

---

### 7.4 Plantilla 4 — Tablero

Banda ancha 1400 × 640 para incrustar en el cuerpo del correo.

1. **Riel izquierdo** (376px, `--navy`), de arriba a abajo:
   - Foto de 110px con degradado a `--navy`.
   - Bloque de texto (`margin-top:-30px`): kicker, `49 Bis` a 44px, ordenamiento, gancho con `border-left: 3px solid var(--acc)`.
   - `Nuestro consejo` en caja `rgba(255,255,255,.07)` con `margin-top:auto`.
   - Franja de contacto en `#061A2E`.
2. **Rejilla derecha**: `grid-template-columns: repeat(3,1fr); grid-template-rows: auto 1fr auto; gap:1px; background: var(--rule)` (el gap con fondo produce las líneas divisorias).
   - Fila 1: `01 ¿Qué es el artículo 49 Bis?` a las 3 columnas, en `flex-direction: row` — título en bloque fijo de 215px, párrafo al lado. **Ojo**: si la celda hereda `flex-direction:column`, el título sale centrado arriba; hay que sobrescribirlo explícitamente.
   - Fila 2: `02 ¿Qué puede hacer el SAT?` · `03 ¿Cuáles son los riesgos?` · `04 ¿Cómo prepararse?`, cada una con su lista repartida a lo alto.
   - Fila 3: franja `--navy-2` con `05 ¿Necesita apoyo?` y los 4 ítems en `repeat(4,1fr)`.

---

## 8. Comportamiento del editor

### Selección de plantilla

Galería con miniaturas (basta un `<canvas>` o capturas estáticas en `assets/`). Al cambiar de plantilla **el contenido editado se conserva**: el estado vive en el objeto de datos, la plantilla solo lo pinta. Cambiar de plantilla nunca debe perder texto.

### Edición

- Modo edición conmutable. Fuera de él, la hoja es solo lectura y no muestra affordances.
- Cada nodo de texto es `contenteditable`. Al perder el foco (`blur`) o con `input` debounced a 300ms, se escribe de vuelta al objeto de datos.
- Pegar debe ser **texto plano**: interceptar `paste` y usar `insertText`. Si no, Word inyecta `<span style="font-family:Calibri">` y rompe el diseño.
- En modo edición, cada `li` muestra un botón `×` para eliminarse (bloquear si es el último de la lista) y cada lista un botón `+ Agregar`.
- Foto: clic sobre ella abre un `<input type="file" accept="image/*">`; se lee con `FileReader` a data URL. Solo aplica a las plantillas 1 y 4.
- Selector de tema con 3 muestras de color.
- Deshacer: basta con `document.execCommand` nativo dentro de cada campo. No construir un sistema de historial.

### Estados visuales (solo en modo edición)

```css
.editando [contenteditable]:hover { outline:1px dashed rgba(29,111,165,.55); outline-offset:3px }
.editando [contenteditable]:focus { outline:2px solid var(--acc); outline-offset:3px;
                                    background:rgba(29,111,165,.06) }
```

---

## 9. Exportación

### PNG

`html2canvas(hoja, { scale: 2, backgroundColor: '#ffffff', useCORS: true })`. Antes de capturar, **quitar la clase `.editando`** y cualquier `transform: scale()` de ajuste a pantalla; restaurarlos después. Nombre sugerido: `boletin-{mes}-{plantilla}.png`.

### Impresión / PDF

`window.print()` con `print.css`. Reglas por plantilla:

| Plantilla | `@page` | Transformación |
|---|---|---|
| 1 y 3 | `size: letter; margin: 0` | ninguna (816×1056 = carta exacta) |
| 2 | `size: A4 landscape; margin: 8mm` | `transform: scale(.72); transform-origin: top left` |
| 4 | `size: A4 landscape; margin: 8mm` | `transform: scale(.75); transform-origin: top left` |

Con `transform` hay que fijar `html, body { width: <ancho×escala>px; height: <alto×escala>px; overflow: hidden }` o Chrome tira una segunda página en blanco. Ocultar barra, galería y controles con `.no-print`.

### Guardar y cargar

- **Guardar JSON**: descarga el objeto de contenido + plantilla + tema como `boletin-2026-08.json`. Es el formato de trabajo para retomar el mes siguiente.
- **Cargar JSON**: `<input type="file">` que repuebla el estado.
- **Guardar HTML**: serializa el DOM actual a un `.html` autocontenido, sin barra de herramientas y sin `contenteditable`. Es el "entregable congelado".
- **Autoguardado** en `localStorage` con clave `boletin:borrador`, restaurado al abrir con aviso discreto y opción de descartar. No sustituye al JSON.

---

## 10. Trampas conocidas

Errores que ya costaron tiempo al prototipar. Vale la pena leerlos antes de escribir CSS.

1. **`img { height:100% }` dentro de un contenedor sin altura definida** resuelve a `auto` y la imagen crece a su tamaño intrínseco, reventando la hoja. Dar alturas explícitas a las filas o `height:100%` al contenedor de la foto.
2. **Filas de flexbox que desbordan**: añadir `min-height:0; overflow:hidden` a los hijos directos de la fila.
3. **Listas que dejan un hueco muerto abajo**: repartir con `ul{flex:1}` + `li{flex:1}` y hairlines entre ítems; queda como tabla y se ve intencional. Ojo con las viñetas `::before` posicionadas (§7.2).
4. **`transform` compuesto**: si un `::before` ya usa `rotate(45deg)` y se centra con `translateY(-50%)`, hay que escribir ambas en la misma declaración.
5. **Especificidad al sobrescribir `flex-direction`** en celdas que heredan `column` de una clase base.
6. **`localStorage` no funciona en `file://` en algunos navegadores** con configuraciones estrictas. Envolver en `try/catch` y degradar silenciosamente al JSON manual.
7. **Texto justificado en itálica** produce espacios enormes. Alinear a la izquierda los bloques en cursiva.

---

## 11. Criterios de aceptación

- [ ] Las 4 plantillas renderizan las 6 secciones, el gancho y el pie, sin recortes ni desbordamientos.
- [ ] Cambiar de plantilla conserva todo el texto editado.
- [ ] Editar un texto, exportar PNG y volver a abrir: el cambio persiste.
- [ ] El PNG exportado es idéntico a lo que se ve en pantalla, sin líneas de edición.
- [ ] Cada plantilla imprime en **una sola página** con la orientación correcta.
- [ ] Agregar y eliminar viñetas funciona en todas las listas de las 4 plantillas.
- [ ] Cambiar la foto funciona en las plantillas 1 y 4.
- [ ] Los 3 temas se aplican sin romper contraste ni layout en ninguna plantilla.
- [ ] Guardar JSON → recargar la página → cargar JSON restaura el estado completo.
- [ ] La app abre con doble clic sobre `index.html`, sin servidor.
- [ ] Sin internet, el botón PNG se oculta y el resto sigue funcionando.
- [ ] Toda la interfaz está en español de México.

---

## 12. Nota de contenido para el despacho

Antes de publicar, el despacho debe validar contra el texto vigente del Artículo 49 Bis del CFF que el plazo de **24 días hábiles** es correcto y está bien caracterizado. En las plantillas 2 y 4 ese dato es el elemento visual dominante, así que un error ahí sería muy visible.

Del mismo modo, ninguna plantilla debe agregar etapas, plazos, porcentajes ni consecuencias que no estén en el documento fuente.

---

## 13. Prompt inicial sugerido para Claude Code

```
Lee ESPECIFICACION-boletin-fiscal.md completo antes de escribir código.

Construye el proyecto en este orden:
1. Estructura de carpetas, tokens.css y contenido.js.
2. Plantilla 1 completa, con edición y exportación PNG funcionando de punta a punta.
3. Valida la plantilla 1 contra los criterios de aceptación antes de seguir.
4. Plantillas 2, 3 y 4.
5. Galería de selección, temas, guardar/cargar, print.css.
6. build.mjs.

Revisa la §10 antes de escribir el CSS de cada plantilla.
La imagen de referencia de cada plantilla está en assets/referencias/.
No modifiques ni un carácter de los textos de la §5.
```

Adjuntar al repositorio, en `assets/referencias/`, los cuatro JPG de las plantillas ya renderizadas — sirven de comparación visual para verificar que la implementación coincide.
