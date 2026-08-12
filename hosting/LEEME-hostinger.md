# Publicar en Hostinger — estructura y contraseña

## Cómo queda el sitio

**Hostinger publica este repositorio tal cual como `public_html`.** Es decir:
la forma del repositorio ES la forma del sitio. No hay que mover nada a mano
—si se moviera, el siguiente despliegue lo desharía—. Por eso el repositorio
está organizado así:

```
/  (= public_html)
├── index.html          ← el portal
├── css/portal.css      ← estilos del portal
├── .htaccess           ← bloquea lo que no es sitio
├── boletin/            ← el generador
│   ├── index.html
│   ├── css/  js/  assets/
│   ├── build.mjs  dist/
├── hosting/            ← estas notas (no se sirven)
└── ESPECIFICACION-…md, …docx  (no se sirven)
```

Con eso, `insusermx.com` abre el portal y `insusermx.com/boletin/` el
generador. Comprobado: el enlace resuelve y el generador pasa su batería
completa desde la subcarpeta, porque todas sus rutas son relativas.

**La siguiente herramienta va en su propia carpeta** en la raíz —
`/tipo-de-cambio/`, `/articulo-69/`, etc. — y se le añade una tarjeta al
portal copiando el bloque `<a class="tarjeta">` comentado en `index.html`.

### Lo que el .htaccess saca del aire

Sin él se descargaban en abierto, por ser parte del repositorio:

- el documento Word original del cliente (1.4 MB),
- la especificación técnica del proyecto,
- `build.mjs` y la carpeta `dist/`.

El archivo bloquea `.md`, `.docx`, `.mjs`, `.json`, los archivos ocultos, la
carpeta `hosting/` y `boletin/dist/`, y apaga el listado de directorios.
Si activas la protección por contraseña desde hPanel y escribe sus líneas en
este mismo archivo, **no borres las reglas que ya están**.

---

## Antes de nada: dónde está expuesto el contenido

Hoy el boletín se puede leer desde **dos** sitios, y ponerle contraseña a uno
solo no sirve de nada:

1. **La web** — `https://insusermx.com/js/contenido.js` se abre en el navegador
   y muestra el texto completo del boletín.
2. **El repositorio de GitHub** — `al2242000122/boletin-fiscal` está en modo
   **público**. Cualquiera descarga el mismo archivo desde
   `raw.githubusercontent.com/.../js/contenido.js` sin pasar por la web.

Hay que cerrar los dos. El paso 2 es un clic; el paso 1 son cinco minutos.

---

## 1. Poner el repositorio en privado

GitHub → el repositorio → **Settings** → hasta abajo, *Danger Zone* →
**Change repository visibility** → *Make private*.

Ojo: quien ya lo haya clonado conserva su copia. Para un boletín que de todas
formas se publica cada mes eso no es grave, pero conviene saberlo.

---

## 2. Contraseña en la web

### Opción A — con el panel de Hostinger (recomendada)

No hay que tocar ningún archivo y es la que menos se rompe:

1. Entra a **hPanel** → tu sitio → sección **Archivos**.
2. Abre **Protección de directorios con contraseña**
   (*Password Protect Directories*; el nombre cambia según la versión).
3. Elige el directorio **`public_html`**.
4. Pon usuario y contraseña y guarda.

Hostinger crea solo el `.htaccess` y el `.htpasswd`, con el hash bien hecho y
el archivo de contraseñas fuera del alcance del navegador.

A partir de ahí, quien entre a `insusermx.com` verá la ventana de usuario y
contraseña del navegador antes de cargar nada. Se aplica a todos los archivos,
también a `js/contenido.js`.

### Opción B — a mano

Solo si prefieres controlarlo tú. Está en `htaccess-boletin.txt`, en esta
misma carpeta: renómbralo a `.htaccess`, súbelo a `public_html/` y corrige la
ruta de `AuthUserFile` con la de tu cuenta.

El `.htpasswd` **tiene que quedar fuera de `public_html`** (por ejemplo en
`/home/uXXXXXXXX/.htpasswds/boletin/`). Si queda dentro, se puede descargar.

Para generar el hash usa la herramienta de hPanel de la opción A, o `htpasswd`
por SSH si tu plan lo incluye. **No uses generadores de hash en páginas
cualquiera de internet**: estarías tecleando la contraseña del despacho en un
sitio ajeno.

---

## Lo que esto NO resuelve

La contraseña protege el acceso al generador. No cifra nada ni sustituye a un
control de usuarios: es una sola clave compartida por todo el despacho. Si
mañana quieren usuarios distintos, con permisos distintos y registro de quién
editó qué, eso ya pide un backend y es otro proyecto.

---

## La alternativa: no publicarlo

La aplicación se diseñó para abrirse con doble clic, sin servidor. Si el
generador solo lo usa el despacho, `dist/boletin.html` es un archivo único y
autocontenido: se deja en OneDrive o en la carpeta compartida y no hace falta
web, ni contraseña, ni repositorio público. Cero superficie expuesta.

La web tiene sentido si necesitan entrar desde fuera de la oficina o desde el
celular. Si no, esta opción es más simple y más segura.
