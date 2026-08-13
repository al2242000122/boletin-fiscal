# Esquemas reales de las listas del SAT

Verificado el **12 de agosto de 2026** descargando los archivos, no leyendo
documentación. Todo lo que sigue está medido. Si algo cambia, este documento
deja de ser cierto: vuelve a correr `php listas/cron/descubrir.php`.

---

## 1. Dónde están los archivos (y dónde ya no)

### Las URLs que circulan por ahí están muertas — pero responden

Las direcciones de `omawww.sat.gob.mx` que aparecen en tutoriales y librerías
**siguen devolviendo HTTP 200**:

```
http://omawww.sat.gob.mx/cifras_sat/Documents/Listado_Completo_69-B.csv   200, 4.5 MB
http://omawww.sat.gob.mx/cifras_sat/Documents/Firmes.csv                  200, 18.6 MB
```

Y ahí está la trampa: sirven datos de **enero de 2026**. Siete meses viejos.
Un ingestor montado sobre ellas no falla nunca y publica información caduca en
silencio. Es el peor modo de fallo posible para este sistema.

### Cadena real hasta el origen vigente

```
omawww…/vinculo.html?page=ListCompleta69.html
   └─ <meta refresh> → omawww…/Paginas/DatosAbiertos/index.html
        └─ <meta refresh> → https://www.sat.gob.mx/minisitio/DatosAbiertos/index.html
             └─ enlace → …/contribuyentes_publicados.html   ← aquí están los archivos
```

El parámetro `?page=` no lo resuelve el servidor: `vinculo.html` es un
redirector y devuelve lo mismo para cualquier valor.

**Origen vigente:** Azure Blob Storage, por HTTPS.

```
https://wu1agsprosta001.blob.core.windows.net/agsc-publicaciones/Datos_abiertos/
    Documents_AGR/      → Artículo 69
    Documents_AGAFF/    → Artículo 69-B
    Documents_AGGC/     → Artículo 69-B Bis
```

Se cae, por tanto, el supuesto de "es http, no https, el servidor es viejo".

### La página índice no necesita JavaScript

Los 25 enlaces están en el HTML crudo de `contribuyentes_publicados.html`.
Una petición normal basta; no hace falta navegador.

### Nombres repetidos

`Sentencias.csv` aparece en dos rutas. La de `AGR/03_02_26/` es una copia
fechada que no se actualiza. **Regla: entre varias, gana la de `Documents_*`.**

---

## 2. Las 15 listas del catálogo

Tamaños y fechas del servidor al 12/08/2026.

### Artículo 69 — `Documents_AGR/`

| clave | archivo | tamaño | fecha servidor |
|---|---|---|---|
| `art69.firmes` | `Firmes.csv` | 20.1 MB | 2026-08-07 |
| `art69.no_localizados` | `No_localizados.csv` | 4.4 MB | 2026-08-07 |
| `art69.cancelados` | `Cancelados.csv` | 20.6 MB | 2026-08-07 |
| `art69.exigibles` | `Exigibles.csv` | 484 KB | 2026-07-24 |
| `art69.csd_sin_efectos` | `CSDsinefectos.csv` | 5.0 MB | 2026-07-24 |
| `art69.entes_publicos` | `EntespublicosydeGobiernoomisos.csv` | 391 KB | 2026-07-24 |
| `art69.sentencias` | `Sentencias.csv` | 51 KB | 2026-05-04 |

### Artículo 69-B — `Documents_AGAFF/`

| clave | archivo | tamaño | fecha servidor |
|---|---|---|---|
| `art69b.completo` | `Listado_completo_69-B.csv` | 4.7 MB | 2026-06-17 |
| `art69b.definitivos` | `Definitivos.csv` | 3.8 MB | 2026-06-17 |
| `art69b.sent_favorables` | `SentenciasFavorables.csv` | 730 KB | 2026-06-17 |
| `art69b.presuntos` | `Presuntos.csv` | 149 KB | 2026-06-17 |
| `art69b.desvirtuados` | `Desvirtuados.csv` | 113 KB | 2026-06-17 |

### Artículo 69-B Bis — `Documents_AGGC/`

| clave | archivo | tamaño | fecha servidor |
|---|---|---|---|
| `bis.completo` | `Listado_69_B_Bis_Completo.csv` | 1.2 KB | 2026-03-12 |
| `bis.definitivos` | `Listado_69_B_Bis_Definitivo.csv` | 954 B | 2026-03-12 |
| `bis.sent_favorables` | `Listado_69_B_Bis_SentenciaFa.csv` | 818 B | 2026-03-12 |

### Cadencia observada

- **Art. 69**: el bloque grande se movió el 7 de agosto; otros el 24 de julio.
  Se actualiza por partes, no todo junto.
- **Art. 69-B**: los cinco archivos, mismo día, 17 de junio. **No es mensual.**
  Dos meses sin publicación al día de hoy.
- **69-B Bis**: marzo. Cinco meses. Y es una lista diminuta: pocas decenas de
  registros en total.

Esto condiciona el producto: **no hay deltas diarios que detectar en 69-B.**

---

## 3. La fecha buena está dentro del archivo

Los archivos de 69-B y 69-B Bis llevan en su primera línea:

```
Información actualizada al 31 de mayo de 2026; los listados a que se hace mención…
```

Pero el `Last-Modified` de ese mismo archivo dice **17 de junio**. Diecisiete
días de diferencia.

**Para las constancias hay que citar la fecha de dentro del archivo**, no la
del servidor. Una constancia mal fechada no sirve como evidencia.

Los archivos del Art. 69 no traen esa línea: ahí la única fecha disponible es
la del servidor, y hay que decirlo así en la constancia.

---

## 4. Formato común

| propiedad | valor |
|---|---|
| codificación | **windows-1252 / latin-1**. No es UTF-8 |
| fin de línea | CRLF |
| delimitador | coma |
| entrecomillado | comillas dobles, con comas dentro de los campos |
| saltos de línea dentro de campo | **sí**, ver §6 |

`fgetcsv` de PHP lee registros completos respetando comillas y saltos internos.
Verificado. Un parser línea a línea rompe.

---

## 5. Esquema por familia

### Artículo 69 — 6 columnas, sin preámbulo

Header en la línea 0.

```
RFC, RAZON SOCIAL, TIPO PERSONA, SUPUESTO, FECHA DE PRIMERA PUBLICACION, ENTIDAD FEDERATIVA
```

Ejemplo real:

```
AAG090703QT6,APLICA AGUASCALIENTES SA DE CV,M,FIRMES,01/01/2014,CIUDAD DE MEXICO
```

- `TIPO PERSONA`: `M` (moral) o `F` (física). Medido en muestra de Firmes:
  1 365 morales, 624 físicas, 1 vacía.
- `SUPUESTO`: coincide con el nombre de la lista (`FIRMES`, `NO LOCALIZADOS`…).
- Fechas en `DD/MM/AAAA`.

### Artículo 69-B — 20 columnas, **2 líneas de preámbulo**

Header en la línea **2** (0-indexada). El preámbulo son dos líneas:

```
[0] "Información actualizada al 31 de mayo de 2026; los listados…"
[1] Listado completo de contribuyentes (Artículo 69-B del CFF),,,,,,…
[2] No,RFC,Nombre del Contribuyente,Situación del contribuyente,…   ← header
```

Columnas:

```
 0  No
 1  RFC
 2  Nombre del Contribuyente
 3  Situación del contribuyente
 4  Número y fecha de oficio global de presunción SAT
 5  Publicación página SAT presuntos
 6  Número y fecha de oficio global de presunción DOF
 7  Publicación DOF presuntos
 8  Número y fecha de oficio global de contribuyentes que desvirtuaron SAT
 9  Publicación página SAT desvirtuados
10  Número y fecha de oficio global de contribuyentes que desvirtuaron DOF
11  Publicación DOF desvirtuados
12  Número y fecha de oficio global de definitivos SAT
13  Publicación página SAT definitivos
14  Número y fecha de oficio global de definitivos DOF
15  Publicación DOF definitivos
16  Número y fecha de oficio global de sentencia favorable SAT
17  Publicación página SAT sentencia favorable
18  Número y fecha de oficio global de sentencia favorable DOF
19  Publicación DOF sentencia favorable
```

**Inconsistencia:** `Presuntos.csv` declara **23** columnas (tres vacías al
final) y su primera columna se llama `No.` con punto, mientras el listado
completo usa `No` sin punto. El parser tiene que tolerar ambas.

### Valores reales de `Situación del contribuyente`

Sobre las **14 523** filas del listado completo, leídas con `fgetcsv`:

| valor | filas |
|---|---|
| Definitivo | 11 771 |
| Sentencia Favorable | 1 658 |
| **Presunto** | **754** |
| Desvirtuado | 340 |

No inventar otros. Son estos cuatro valores, y no hay filas con la situación
vacía.

> **Corrección del 12/08/2026.** Un primer conteo hecho leyendo el archivo
> línea a línea daba 14 536 filas, 753 presuntos y 15 situaciones vacías. Era
> incorrecto: contaba como filas las líneas de relleno (`,,,,,`) y partía en dos
> los registros que llevan un salto de línea dentro de un campo. Los números
> buenos son los de arriba, medidos con un lector de CSV de verdad.

### Artículo 69-B Bis — 12 columnas, 2 líneas de preámbulo

Misma estructura, sin las columnas de presunción ni desvirtuados:

```
No., RFC, Nombre del Contribuyente, Situación del contribuyente,
Número y fecha de oficio global definitivo SAT, Publicación página SAT definitivo,
Número y fecha de oficio global definitivo DOF, Publicación DOF definitivo,
Número y fecha de oficio global de sentencia favorable SAT, Publicación página SAT sentencia favorable,
Número y fecha de oficio global de sentencia favorable DOF, Publicación DOF sentencia favorable
```

---

## 6. Basura confirmada en los datos

Hay que manejarla desde el primer día, no descubrirla en producción.

**Clasificación de RFC** en el listado completo de 69-B (14 523 filas):

| resultado | filas |
|---|---|
| moral (12 caracteres) | 11 139 |
| física (13 caracteres) | 3 293 |
| **suprimido** | **91** |

En `Presuntos.csv` (754 filas): 15 suprimidos.

**Registros tachados por el propio SAT.** 91 filas traen el RFC como
`XXXXXXXXXXXX` y el nombre como *"Información suprimida en cumplimiento de la
Ley Federal de Protección de Datos Personales"*. No son basura ni errores de
captura: son filas reales con el dato retirado a propósito. Hay que
conservarlas marcadas como suprimidas, no descartarlas.

**RFC con Ñ.** Hay 98 en el listado. La `Ñ` ocupa **dos bytes** en UTF-8, así
que contar con `strlen` da 13 o 14 caracteres y los descarta por longitud
inválida. Hay que usar `mb_strlen`. Ejemplos reales: `ÑAÑ140114GY4`,
`ÑEX121116KM4`.

**Campos vacíos donde no deberían:** en la muestra de `Firmes.csv`, una fila
sin `SUPUESTO` y sin `TIPO PERSONA`.

**Registro partido en dos líneas** — `Listado_69_B_Bis_Completo.csv`, fila 2:

```
2,CPH061010RB7,"CONSTRUCTORA DE PROYECTOS HIDROELECTRICOS, 
S.A. DE C.V.",Definitivo,900-04-00-00-00-2024-80 de 25 de enero de 2024,…
```

El salto de línea está **dentro** del campo entrecomillado. Con `fgetcsv` se
lee bien; leyendo por líneas se parte el registro y se corrompen dos filas.

**Formato de oficio inconsistente** en 69-B, tres variantes conviviendo:

```
500-05-2025-39537 de fecha 16 de diciembre de 2025
500 05 2026 11484 de fecha 08 de abril de 2026        ← con espacios
500-05-00-00-00-2026-16026 de fecha 08 de mayo de 2026 ← formato largo
```

No parsear el número de oficio con una sola expresión. Guardar el texto tal
cual y, si hace falta, extraer la fecha aparte.

---

## 7. Volumen para dimensionar

- 69-B completo: **14 523 filas**
- 69-B presuntos: **754 filas**
- Firmes: 20 MB ≈ 250 000 filas estimadas
- Cancelados: 20.6 MB, orden similar

Total esperado por debajo del millón de filas. MySQL con índice en RFC lo
resuelve sin esfuerzo; no hace falta Postgres.

---

## 8. Fuera de alcance: Artículo 49 Bis

No hay archivo consolidado descargable. Requiere raspar el DOF. Queda
documentado como fase futura, sin implementar.

---

## 9. Cómo volver a comprobar todo esto

```
php listas/cron/descubrir.php            informe legible
php listas/cron/descubrir.php --json     salida para máquinas
```

Avisa si una lista del catálogo desaparece del índice, si un nombre de archivo
está repetido en varias rutas, y qué archivos publica el SAT que el catálogo no
contempla.
