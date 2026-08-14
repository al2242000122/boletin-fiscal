# Listas del SAT — lo que falta

Documento de trabajo. Escrito el 13 de agosto de 2026 leyendo el código real de
`listas/`, no la idea del proyecto.

---

## 0. Dónde está el proyecto hoy

El módulo `listas/` son 15 archivos, ~2 800 líneas. Lo que ya funciona:

| pieza | archivo | estado |
|---|---|---|
| Descubrimiento de URLs del SAT | `cron/lib/fuentes.php` | completo |
| Lectura de los CSV (1252, preámbulo, Ñ, suprimidos) | `cron/lib/csv_sat.php` | completo |
| Ingesta + historial SCD-2 + deltas | `cron/lib/ingestor.php` | completo |
| Esquema MySQL | `cron/esquema.sql` | completo |
| Aviso por correo de urgentes | `cron/lib/aviso.php` | completo |
| Panel de administración | `index.php` | completo |
| Tablero de alertas | `alertas.php` | completo |
| Consulta de **un** RFC + listado paginado | `consulta.php` | completo |

Lo que **no** existe todavía, y es justo lo que convierte esto en producto
vendible:

1. **Consulta por lote** — un contador tiene 300 proveedores, no uno.
2. **Salida machine-readable (API)** — para que el Excel del despacho consulte
   solo, sin pegar CSVs a mano.
3. **Constancia con fecha** — el entregable que va al expediente.

El esquema ya las anticipa: `bitacora.origen` contempla `'lote'` y `'api'`, y
`bitacora.cantidad` lleva el comentario *"para consultas por lote"*. Están
diseñadas y sin construir.

---

## 1. Por qué estas tres y no otras

El caso de uso real es el papel de trabajo de ISR/IVA que usan los despachos:
un Excel con botón «Verificar 69-B» que hace `BUSCARV` contra un CSV pegado a
mano. Eso tiene tres fallos que este proyecto ya puede resolver y ese Excel no:

- **El CSV pegado envejece en silencio.** Aquí `fuentes.php` redescubre las URLs
  en cada corrida, precisamente porque las direcciones viejas de `omawww`
  siguen devolviendo 200 con datos de enero.
- **No distingue situaciones.** `BUSCARV` dice «está en la lista». La base
  distingue Presunto / Definitivo / Desvirtuado / Sentencia Favorable, y el
  plazo de 15 días hábiles para desvirtuar solo corre en el primero.
- **No deja rastro.** No puede contestar *«al 15 de julio este RFC no
  aparecía»*. La tabla `estatus` con `valido_desde` / `valido_hasta` sí. Eso es
  lo que sirve en una revisión, y es lo que nadie más vende.

La constancia (fase 3) es la que realmente diferencia. Las otras dos son
plomería para llegar a ella.

---

## 2. Fase 1 — `listas/lote.php`

**Qué hace:** se pegan RFC (o se sube un CSV) y devuelve una tabla con la
situación vigente de cada uno, descargable.

### Entrada

- `<textarea>` con RFC separados por salto de línea, coma, punto y coma o
  espacio. Tolerar que vengan con guiones y espacios: `csv_sat_rfc()` ya
  normaliza eso.
- Subida opcional de `.csv` / `.txt`. Se toma **la primera columna que parezca
  RFC**, no la primera columna a secas: el contador va a subir su listado de
  proveedores completo, con nombre y saldo.
- Tope duro: **5 000 RFC por lote**. Más que eso, avisar y no procesar.

### Proceso

1. Normalizar cada entrada con `csv_sat_rfc()`. Separar en tres cubetas:
   válidos, inválidos (con el motivo que ya devuelve la función) y duplicados.
2. Deduplicar antes de consultar.
3. Consultar en trozos de **500 marcadores** por sentencia. No armar un `IN`
   con 5 000 placeholders: `PDO::ATTR_EMULATE_PREPARES` está en `false`, así que
   cada uno viaja al servidor.
4. La consulta base:

   ```sql
   SELECT e.rfc, e.lista, e.situacion, e.supuesto, e.nombre, e.tipo_persona,
          e.entidad, e.proc_texto, e.valido_desde, s.fecha_archivo
   FROM estatus e
   JOIN snapshots s ON s.id = e.snapshot_desde
   WHERE e.vigente = 1 AND e.rfc IN (…)
   ```

   Usa el índice `ix_rfc`. Para 5 000 RFC contra ~300 000 filas no hay que hacer
   nada más.

5. Un RFC puede aparecer en **varias listas** (69-B completo, Definitivos,
   Firmes, Cancelados…). No colapsar a una fila: agrupar por RFC y mostrar todas
   sus apariciones. Al mismo tiempo, calcular un veredicto por RFC con esta
   prioridad, que es la que le importa al contador:

   ```
   1. 69-B Definitivo        → no deducible, efectos fiscales anulados
   2. 69-B Presunto          → URGENTE, corren 15 días hábiles
   3. 69-B Bis (cualquiera)  → transmisión indebida de pérdidas
   4. Art. 69 (Firmes, No localizados, Cancelados, CSD sin efectos…)
   5. 69-B Desvirtuado / Sentencia Favorable → aparece, pero sin efecto adverso
   6. No aparece
   ```

   Ojo con el punto 5: **desvirtuado y sentencia favorable no son un problema**,
   y presentarlos en rojo junto a los definitivos es un error que le costaría
   un proveedor bueno al cliente.

6. Los RFC que no aparecen son la mayoría y son un resultado válido, no un
   error. Decirlo así: *«no aparece en ninguna lista al 31 de mayo de 2026»*,
   con la fecha del preámbulo del archivo.

### Salida

- Tabla en pantalla, ordenada por gravedad del veredicto (los definitivos
  arriba), con los «no aparece» colapsados en un bloque al final.
- Descarga CSV. **Con BOM UTF-8** (`\xEF\xBB\xBF`): sin él, Excel en Windows
  abre el archivo como ANSI y parte los nombres con acentos y las Ñ. Es el
  mismo problema que ya está documentado para la lectura, ahora en la escritura.
- Columnas del CSV: `RFC, NOMBRE, VEREDICTO, LISTA, SITUACION, SUPUESTO,
  TIPO_PERSONA, ENTIDAD, OFICIO, VIGENTE_DESDE, FECHA_LISTA_SAT, CONSULTADO_EN`.

### Bitácora

Un solo registro por lote, no uno por RFC:

```php
origen = 'lote', rfc_consultado = NULL, cantidad = <n de RFC consultados>,
resultado = '<n> con hallazgo de <total>'
```

---

## 3. Fase 2 — `listas/api.php`

**Qué hace:** el mismo dato, en JSON, para que Power Query lo consuma desde el
Excel del despacho.

### Autenticación

`acceso_exigir()` **no sirve aquí**: redirige con `Location:` y Power Query
seguiría el redirect y recibiría el HTML del login con un 200. Hay que
autenticar con clave:

- Claves en `privado/config.php`, nueva constante:

  ```php
  const API_CLAVES = [
      'despacho-iss'  => 'cadena-larga-aleatoria',
      'cliente-demo'  => 'otra-cadena',
  ];
  ```

  Añadirla también a `privado/config.php.ejemplo` — ese sí va al repositorio.
- Se acepta en cabecera `X-Clave:` o en `?clave=`. Comparar siempre con
  `hash_equals()`, nunca con `==`.
- Sin clave o clave mala: `401` con cuerpo JSON, no HTML.

### Contrato

`GET /listas/api.php?rfc=AAA080808HL8`

```json
{
  "ok": true,
  "rfc": "AAA080808HL8",
  "aparece": true,
  "veredicto": "definitivo_69b",
  "resumen": "Definitivo en el listado del 69-B",
  "resultados": [
    {
      "lista": "art69b.completo",
      "etiqueta": "Art. 69-B · Listado completo",
      "situacion": "Definitivo",
      "supuesto": null,
      "nombre": "EJEMPLO SA DE CV",
      "tipo_persona": "M",
      "oficio": "500-05-2025-39537 de fecha 16 de diciembre de 2025",
      "vigente_desde": "2026-05-31",
      "fecha_lista_sat": "2026-05-31"
    }
  ],
  "consultado_en": "2026-08-13T14:22:05-06:00"
}
```

`POST /listas/api.php` con `{"rfcs": ["...", "..."]}`, tope **500**, devuelve
`{"ok":true,"resultados":{"RFC": {…}, …}}`.

### Detalles que no se pueden saltar

- `header('Content-Type: application/json; charset=utf-8')` y
  `json_encode(..., JSON_UNESCAPED_UNICODE)`.
- **Nunca** devolver HTML. Envolver todo en try/catch y que el catch también
  emita JSON con `ok:false`. Un warning de PHP impreso antes del JSON rompe el
  parser de Power Query con un mensaje inútil.
- Códigos: `200` encontrado o no encontrado (ambos son respuestas válidas),
  `400` RFC mal formado, `401` clave, `413` lote demasiado grande, `500` error.
- Bitácora con `origen = 'api'` y el nombre de la clave usada en `usuario`.
- Límite de tasa sencillo: contar en `bitacora` las consultas de esa clave en
  la última hora y cortar en un número razonable. No hace falta Redis.

### Nota para el consumidor

Dejar en el repo un `listas/docs/EXCEL.md` de media página con los pasos de
Power Query: **Datos → Obtener datos → Desde web**, pegar la URL con la clave,
expandir la columna `resultados`. Sin eso el contador no lo va a usar aunque
funcione.

---

## 4. Fase 3 — `listas/constancia.php`

**El entregable.** Documento imprimible que acredita el estado de un RFC en una
fecha, con la cadena de evidencia completa.

### Nada de librerías de PDF

El proyecto ya resuelve esto: `/boletin` genera PDF con **HTML + `print.css` +
imprimir del navegador**. No hay `composer.json` y no lo va a haber en
Hostinger. Seguir el mismo camino.

De `boletin/css/print.css` hay que heredar dos decisiones y **no deshacerlas**:

- **`margin: 0` en `@page`.** El navegador dibuja su encabezado y su pie (URL,
  fecha, número de página) *dentro* del margen. Sin margen no tiene dónde
  ponerlos. Ese fue el problema que el contador leyó como «marcas de agua».
- **Carta, no A4.** El despacho imprime en Carta.

### Qué lleva la constancia

```
CONSTANCIA DE CONSULTA — LISTADOS DEL ARTÍCULO 69 / 69-B / 69-B BIS DEL CFF

RFC consultado:    AAA080808HL8
Nombre:            EJEMPLO SA DE CV        (o "no disponible")
Fecha de consulta: 13 de agosto de 2026, 14:22 h (hora del centro de México)

RESULTADO
  [ redacción exacta según el caso: aparece / no aparece / en qué situación ]

FUENTE CONSULTADA
  Archivo:              Listado_completo_69-B.csv
  Publicado por:        Servicio de Administración Tributaria
  Información al:       31 de mayo de 2026     ← del preámbulo del CSV
  Descargado el:        17 de junio de 2026, 08:14 h
  Huella SHA-256:       a3f1c9…  (los 64 caracteres, en monoespaciada)
  URL de origen:        https://wu1agsprosta001.blob.core.windows.net/…

HISTORIAL DEL RFC EN LOS LISTADOS
  [ tabla desde `estatus`: lista, situación, oficio, desde, hasta ]

  Sin movimientos previos registrados.   ← si aplica

Consulta registrada con folio C-000184 el 13/08/2026 a las 14:22 h.
```

### Reglas duras

- **La fecha que se cita es `snapshots.fecha_archivo`** (la del preámbulo del
  CSV), nunca `fecha_servidor`. Están medidos 17 días de diferencia. Una
  constancia mal fechada no sirve de evidencia y es peor que no emitirla.
- Cuando `fecha_archivo` es `NULL` — pasa en todo el Artículo 69, que no trae
  esa línea — **decirlo explícitamente en el documento**: *«Este listado no
  declara fecha de actualización en el archivo; se cita la fecha de publicación
  del servidor: …»*. No rellenar el hueco con la fecha del servidor en silencio.
- El **«no aparece» es el caso más frecuente y el más útil**. Redactarlo con el
  mismo cuidado que el hallazgo: *«Consultados los listados vigentes al 31 de
  mayo de 2026, el RFC AAA080808HL8 no fue localizado en ninguno de ellos.»*
- Folio consecutivo desde `bitacora.id` con prefijo. Que sea verificable
  después.
- La constancia **no dictamina**. No escribir «este proveedor es deducible».
  Se acredita qué decía el listado en tal fecha, punto. Cerrar con una nota
  breve: es una consulta a información pública, no una opinión fiscal.

### Ruta

`constancia.php?rfc=XXX` desde el botón de `consulta.php`, y `?lote=<id de
bitácora>` para emitir el paquete de un lote completo (una constancia por
página, `page-break-after`).

---

## 5. Deuda técnica encontrada de paso

Nada de esto bloquea las tres fases, pero conviene resolverlo.

### 5.1 Credenciales en repositorio público — atender ya

`acceso.php` lleva en el código, y por tanto en el historial de git:

```php
const ACCESO_HASH    = '$2y$10$VWazyWlY5g7UVn3YrbVq0.…';
const ACCESO_USUARIO = 'Insuser@2026';
```

El repositorio es público. El hash es bcrypt y no se revierte, pero el usuario
está en claro y el hash queda expuesto a ataque por diccionario sin límite de
intentos y sin que nadie se entere. Mover ambas constantes a
`privado/config.php`, que ya está en `.gitignore` y bloqueado por `.htaccess`,
dejar en `acceso.php` solo la lectura, **cambiar la contraseña** y reescribir el
historial o asumir que la vieja está quemada.

Añadido: `ACCESO_MAX_INTENTOS = 90` — con 90 intentos antes de esperar 5
minutos, el bloqueo casi no existe. Probablemente se subió para depurar. Bajar
a 5–10.

### 5.2 Índice para la búsqueda por nombre

`consulta.php` filtra con `nombre LIKE '%texto%'`. El comodín inicial impide
usar índice: recorre la tabla entera. Con 300 000 filas de `Firmes` se va a
notar. Un `FULLTEXT` sobre `nombre` y `MATCH … AGAINST` en modo booleano lo
resuelve; alternativa barata: exigir 3 caracteres mínimo y buscar por prefijo.

### 5.3 `fecha_servidor` nunca se llena

En `ingestor.php`, el `INSERT` de `snapshots` pasa `null` en esa columna, aunque
`fuentes_fecha_servidor()` existe y funciona. Para el Artículo 69 esa es la
**única** fecha disponible, y la fase 3 la necesita. Hay que capturar las
cabeceras en `descargar()` y guardarla.

### 5.4 Ingesta de golpe

`ingestar()` recorre las 15 listas en una sola pasada. Sumadas son ~75 MB y
`Firmes` sola son 250 000 filas. En un plan compartido de Hostinger eso puede
chocar con `max_execution_time` o con el límite de memoria. Vale la pena poder
correr el cron por grupos (`--grupo=art69b` ya está soportado en el filtro) y
escalonarlo en el crontab.

### 5.5 Sin pruebas

`probar_parser.php` es un script de inspección manual, no una prueba. Para tres
cosas concretas — la fila partida del `69_B_Bis_Completo`, los RFC con Ñ, los
suprimidos — bastarían tres CSV pequeñitos en `listas/pruebas/` y un script que
verifique el conteo. Son casos ya conocidos y documentados; el riesgo es que una
refactorización futura los rompa sin que nadie se entere.

---

## 6. Orden sugerido

1. `lote.php` — es el que más valor da por hora de trabajo y no toca nada
   existente.
2. 5.3 (`fecha_servidor`) — es media hora y la fase 3 depende de ello.
3. `constancia.php` — el diferenciador.
4. `api.php` + `docs/EXCEL.md`.
5. 5.1 (credenciales) en cuanto haya un rato, independientemente de lo demás.
