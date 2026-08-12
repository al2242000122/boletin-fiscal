# Publicar en Hostinger — estructura, acceso y contraseña

## Acceso con pantalla propia (PHP)

El sitio tiene su propia pantalla de acceso, no la ventana gris del navegador.
Funciona en el servidor, no en el navegador, y por eso sí protege: la
aplicación vive en `privado/boletin.html`, una carpeta que el `.htaccess`
bloquea, y solo `boletin.php` la entrega tras comprobar la sesión. Sin
sesión no hay ningún archivo que se pueda pedir por URL.

### Puesta en marcha (una sola vez)

1. Sube todo y entra a **`insusermx.com/_configurar.php`**.
2. Escribe la contraseña del despacho (mínimo 10 caracteres) y pulsa Generar.
3. Copia la línea que aparece y sustituye con ella la de `acceso.php`:
   `const ACCESO_HASH = 'PENDIENTE';`
4. Sube `acceso.php` y **borra `_configurar.php` del servidor**.
   (Se desactiva solo en cuanto hay contraseña, pero mejor no dejarlo.)
5. El usuario es `despacho`. Para cambiarlo, edita `ACCESO_USUARIO`.

La contraseña nunca se guarda en claro: solo su hash bcrypt. Cinco intentos
fallidos bloquean cinco minutos.

### Cuando funcione, quita la contraseña vieja

La protección de directorios de hPanel y esta pantalla se pisan: saldrían dos
peticiones de contraseña seguidas. **Primero comprueba que la pantalla nueva
funciona**, y solo entonces quita la de hPanel (Avanzado → Proteger
directorios con contraseña → eliminar `public_html` de la lista). No al
revés: entre una y otra el sitio quedaría abierto.

---

## Cómo queda el sitio

**Hostinger publica este repositorio tal cual como `public_html`.** Es decir:
la forma del repositorio ES la forma del sitio. No hay que mover nada a mano
—si se moviera, el siguiente despliegue lo desharía—. Por eso el repositorio
está organizado así:

```
/  (= public_html)
├── index.php           ← acceso + portal
├── boletin.php         ← entrega la app tras comprobar la sesión
├── salir.php  acceso.php  _configurar.php
├── css/portal.css
├── .htaccess           ← bloquea privado/, boletin/ y material de desarrollo
├── privado/
│   └── boletin.html    ← la app en un solo archivo (la genera build.mjs)
├── boletin/            ← código fuente (bloqueado por el .htaccess)
└── hosting/            ← estas notas (bloqueadas)
```

Con eso, `insusermx.com` pide la contraseña y luego muestra el portal, y el
generador se abre desde `boletin.php`. No hay ninguna URL que devuelva la
aplicación sin sesión.

**La siguiente herramienta** se añade igual: su código fuente en una carpeta
bloqueada, un `.php` en la raíz que compruebe la sesión con
`acceso_exigir()` y la entregue, y una tarjeta en el portal copiando el
bloque `<a class="tarjeta">` de `index.php`.

### Lo que el .htaccess saca del aire

Sin él se descargaban en abierto, por ser parte del repositorio:

- el documento Word original del cliente (1.4 MB),
- la especificación técnica del proyecto,
- `build.mjs` y la carpeta `dist/`.

El archivo bloquea `.md`, `.docx`, `.mjs`, `.json`, los archivos ocultos, las
carpetas `hosting/`, `privado/` y `boletin/`, y apaga el listado de
directorios.

---

## Antes de nada: dónde está expuesto el contenido

Hoy el boletín se puede leer desde **dos** sitios, y ponerle contraseña a uno
solo no sirve de nada:

1. **La web** — resuelto con la pantalla de acceso PHP.
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

Ya está resuelta con la pantalla PHP del principio de este documento. La
protección de directorios de hPanel sigue siendo una alternativa válida —más
simple, pero con la ventana gris del navegador— y está descrita en
`htaccess-boletin.txt`, en esta misma carpeta.

Si usas las dos a la vez el sitio pedirá la contraseña dos veces seguidas.

---

## Lo que esto NO resuelve

La contraseña protege el acceso al generador. No cifra nada ni sustituye a un
control de usuarios: es una sola clave compartida por todo el despacho. Si
mañana quieren usuarios distintos, con permisos distintos y registro de quién
editó qué, eso ya pide un backend y es otro proyecto.

---

## La alternativa: no publicarlo

La aplicación se diseñó para abrirse con doble clic, sin servidor. Si el
generador solo lo usa el despacho, `privado/boletin.html` es un archivo único y
autocontenido: se deja en OneDrive o en la carpeta compartida y no hace falta
web, ni contraseña, ni repositorio público. Cero superficie expuesta.

La web tiene sentido si necesitan entrar desde fuera de la oficina o desde el
celular. Si no, esta opción es más simple y más segura.
