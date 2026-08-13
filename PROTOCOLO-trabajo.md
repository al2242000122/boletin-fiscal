# Protocolo de trabajo

Cómo se construye este proyecto. Escrito el 13 de agosto de 2026 a partir de lo
que de verdad ha funcionado y de lo que de verdad ha fallado en `boletin/` y
`listas/`, no de buenas intenciones.

Los archivos `.md` están bloqueados por `.htaccess`, así que este documento no
se sirve por web aunque viva en la raíz del repositorio.

---

## 1. La compuerta va por riesgo, no por archivo

Parar en cada archivo suena prudente y no lo es: trata igual veinte líneas
mecánicas que la redacción de un documento que va a un expediente. Lo que
cambia el coste de equivocarse no es el tamaño del cambio, es **quién sufre el
error y si se nota**.

### Nivel 1 — Texto que lee un tercero

La constancia, los veredictos, el correo de alerta, las notas legales, los
mensajes de error que ve el contador.

**Se para siempre.** Y se enseña el **texto redactado en plano**, no el PHP que
lo imprime. Aprobar una redacción leyendo código obliga a hacer dos trabajos a
la vez, y el que se hace mal es el de la redacción.

Orden invertido a propósito: primero el texto, se revisa, y solo entonces se
envuelve en PHP.

### Nivel 2 — Decisión de producto

La prioridad del veredicto, qué cuenta como hallazgo, qué se colapsa y qué no,
el tope de RFC por lote, qué expone la API.

**Se para antes de escribir código**, con los avisos numerados y una
recomendación por cada uno. Se contesta sí o no y se sigue.

Es barato y evita trabajo tirado: los tres avisos sobre `lote.php` —la fecha del
`JOIN`, el «no aparece» sin fecha única, las sublistas que duplican al listado
completo— costaron un mensaje y habrían costado media tarde descubiertos después.

### Nivel 3 — Plomería

`fecha_servidor`, los trozos de 500 marcadores, el BOM del CSV, migraciones,
índices, redirecciones, zonas horarias.

**No se para.** Se hace, se ejecuta y se enseña la medición. Si está mal, se ve
en la salida; pedir permiso para algo que se comprueba ejecutando gasta un turno
y no compra nada.

### Cómo se clasifica cuando hay duda

Sube de nivel. Y una regla que no admite excepción: **un cambio que toca cómo se
describe la situación fiscal de un contribuyente nunca es nivel 3**, aunque sea
una palabra.

---

## 2. Nada se da por hecho sin haberlo ejecutado

Es la regla que ha sostenido la calidad de este proyecto, y no es una opinión:
es el recuento.

| defecto | se encontró |
|---|---|
| Colación distinta en la tabla temporal: los `JOIN` fallaban | ejecutando |
| Idempotencia envenenada: una carga a medias dejaba la lista muerta | ejecutando |
| Altas fantasma: 5 cambios generaban 5 altas de más | ejecutando |
| `ingesta.php` terminaba en `exit()` y mataba la petición del panel | ejecutando |
| `strlen` descartaba 98 RFC con Ñ | ejecutando |
| Bucle de redirección sin sesión en `listas/*.php` | ejecutando |
| Horas seis adelantadas: el servidor corre en UTC | ejecutando |
| Mis propios conteos: 14 536 filas, 753 presuntos, 15 vacías | ejecutando |

Ocho defectos. **Ninguno se encontró leyendo.** El último es el que más importa
del recuento, porque era mío y lo afirmé con el mismo tono de seguridad que uso
para los datos correctos.

De ahí sale la disciplina que sí es negociable en la forma pero no en el fondo:
**cada número que se afirma dice cómo se obtuvo**. Medido con `fgetcsv`,
estimado por tamaño de archivo, o leído de la documentación del SAT. No es lo
mismo y no puede sonar igual.

### El banco de pruebas local es infraestructura, no andamio

PHP portable y MariaDB en el puerto 3399 con una copia del sitio servida por el
servidor de desarrollo. Sin eso, todo lo de la tabla de arriba se habría
descubierto en producción, con datos del despacho dentro.

Cuando se pierda —se pierde con el contexto—, se vuelve a levantar. No se
sustituye por leer el código con atención.

---

## 3. El simulacro de publicación pasa a ser del repositorio

Es lo que más ha valido por minuto invertido en toda la sesión y hoy vive en un
directorio temporal que se va a borrar.

**Qué hace:** parte del archivo real de mayo ya archivado, fabrica la
publicación de junio que el SAT todavía no ha hecho —dos contribuyentes pasan de
Presunto a Definitivo, uno desaparece del archivo, entra uno nuevo como
Presunto—, la ingiere y comprueba que salen exactamente 1 alta, 2 cambios,
1 baja, 3 urgentes, y que los 28 864 eventos de la carga inicial no se cuelan.

Sin él, la cadena completa —descarga, parseo, delta, alerta, correo— solo se
puede probar esperando a que el SAT publique, que en 69-B es cada uno o dos
meses.

**Junto a él, los tres casos difíciles que ya están documentados** en
`docs/ESQUEMAS.md` y que una refactorización rompe en silencio:

- el registro partido en dos líneas de `Listado_69_B_Bis_Completo.csv`
- los 98 RFC con Ñ
- los 91 registros suprimidos por la LFPDPPP

Tres CSV diminutos en `listas/pruebas/` y un `php listas/pruebas/correr.php` que
imprima OK o FALLO por caso. Una hora de trabajo. Protege exactamente los
lugares donde ya sabemos que se rompe.

---

## 4. Falta el lazo de realimentación desde el servidor

El agujero real de este proyecto: **todo se verifica en local y se despliega a
ciegas.** Tres incidentes, el mismo agujero.

- `privado/config.php` con texto antes de `<?php`, imprimiéndose en la página.
- El bucle de redirección, que llevaba semanas ahí porque nadie entra sin sesión.
- La duda de ahora mismo: no sabemos si el cron corre.

**Propuesta: una página de comprobación** que se abre después de cada despliegue
y pinta en verde o rojo:

- `config.php` limpio y conectando
- columnas del esquema al día
- constancia del cron y cuántos días hace
- correo configurado y probado
- listas con error en la última corrida
- desfase entre la hora del servidor y la de México
- listas del catálogo que ya no aparecen en el índice del SAT

Parte ya existe repartida por el panel. Reunirla en un sitio la convierte en un
gesto: se despliega, se abre, se pega el resultado. **Esa es la única ventana
que tengo al servidor**, y hoy no existe.

---

## 5. Cómo se documenta

Lo que ya se hace y no hay que tocar: **cada decisión rara lleva encima el dato
medido que la justifica.** No «se usa `mb_strlen`», sino «se usa `mb_strlen`
porque la Ñ ocupa dos bytes y hay 98 RFC con Ñ en el listado».

Un comentario que solo repite lo que dice el código se borra.

**Las correcciones se quedan visibles.** Cuando un número medido resulta estar
mal, no se sobreescribe en silencio: se corrige y se deja la nota de qué decía
antes y por qué estaba mal, como la del 12/08 en `ESQUEMAS.md` sobre las 14 536
filas. Es lo que hace que el resto de los números del documento se puedan creer.

---

## 6. Qué se hace sin preguntar y qué no

**Sin preguntar:** leer cualquier cosa, levantar el banco de pruebas, ejecutar
scripts contra la base local, escribir y corregir código de nivel 3, `commit`
local.

**Avisando antes, siempre:** `privado/`, `.htaccess`, `acceso.php`. Y el
`git push`, que es la acción que sale del ordenador: **solo cuando se pida**.
Hasta ahora se ha empujado cuando el cambio hacía falta en el servidor; la
regla estrecha es mejor y es la que se adopta.

**Nunca:** inventar un dato del SAT. Lo medido está en `docs/ESQUEMAS.md`; si
hace falta uno que no está, se dice y se verifica con
`php listas/cron/descubrir.php`.

---

## 7. Orden de trabajo revisado

| # | trabajo | por qué ahí |
|---|---|---|
| 1 | `fecha_servidor` (5.3) | veinte minutos, la constancia depende de ello, y hacerlo ahora evita volver a `ingestor.php` con la cabeza en otra cosa |
| 2 | `lote.php` | lo que más valor da por hora y no toca nada existente |
| 3 | `listas/pruebas/` | antes de la constancia, que es el archivo que no se puede permitir romper |
| 4 | `constancia.php` | el diferenciador; texto primero, código después |
| 5 | Credenciales (5.1) | **antes** que la API: construir un segundo sistema de claves mientras el primero está quemado en el historial de git es trabajo que se repite |
| 6 | `api.php` + `docs/EXCEL.md` | |

Los cambios frente al orden del documento de pendientes son dos: `fecha_servidor`
sube al primer puesto y las credenciales se adelantan a la API.

---

## 8. Dónde está el riesgo de verdad

No en el código: en la constancia.

Es el único archivo donde se puede escribir una frase que suene competente y sea
una opinión fiscal encubierta. Y se va a leer con la guardia baja, porque el
resto del documento va a estar bien.

Lo que no puede aparecer, ni una vez:

- Nada que empiece por «por lo tanto» o «en consecuencia».
- Ningún adjetivo sobre deducibilidad, ni a favor ni en contra.
- Ninguna palabra que sugiera que un resultado negativo certifica algo. La
  constancia acredita **qué decía el listado en tal fecha**, y ahí se acaba.
- La palabra «limpio» aplicada a un contribuyente. Hoy está como clase de CSS
  en `consulta.php`; en el documento no entra.

**Procedimiento para ese archivo:** primero el texto plano de los tres casos
—aparece con efecto adverso, aparece sin efecto adverso, no aparece—, se revisa,
se aprueba, y entonces se implementa. Si hay ocasión de que lo lea el contador
antes, mejor.

---

## 9. Lo que no se cambia

- **Todo en español.** Nombres, comentarios, interfaz. Sin cambio de idioma a
  media línea.
- **PHP 8 plano, sin dependencias.** Es la realidad de Hostinger, no una
  preferencia estética.
- **El comentario con el porqué medido.** Es la razón de que este proyecto se
  pudiera retomar después de perder el contexto entero sin volver a romper lo ya
  arreglado. Vale más que cualquier prueba automática que se escriba.

---

## 10. Anotado de paso, sin tocar

- El comentario del `.htaccess` sobre `listas/` dice que «hoy no tiene interfaz
  web: solo scripts de cron y documentación». Dejó de ser cierto: hay cuatro
  páginas servidas. La regla que bloquea `listas/cron` y `listas/docs` sigue
  siendo la correcta; el comentario está viejo.
