# Pruebas de la herramienta de listas

```
php listas/pruebas/correr.php
```

Sin más, corre todo lo que no necesita base de datos: 48 comprobaciones sobre
la normalización de RFC, la lectura de los CSV y las fechas. El simulacro de
publicación se omite y lo dice.

Para correrlo entero hace falta una base de datos **de usar y tirar**:

```
BD_HOST=127.0.0.1 BD_PUERTO=3399 BD_NOMBRE=pruebas_listas BD_USUARIO=root \
    php listas/pruebas/correr.php
```

73 comprobaciones. Devuelve 0 si todo va bien y 1 si algo falla, así que sirve
para un gancho de git o para lo que sea.

## Por qué la base va por variables de entorno

El simulacro crea tablas, carga datos y los borra. Si leyera
`privado/config.php` como todo lo demás, una equivocación tonta correría eso
contra la base de producción. Al exigir `BD_HOST` explícito, no puede pasar:
sin esa variable la prueba se omite en vez de buscarse una base por su cuenta.

## Qué cubren y qué no

**No cubren todo el módulo a propósito.** Cubren los sitios donde esto ya se ha
roto, que están medidos y documentados en `docs/ESQUEMAS.md`:

| se prueba | porque |
|---|---|
| RFC con Ñ | ocupa dos bytes en UTF-8; con `strlen` se descartaban 98 contribuyentes |
| Registros suprimidos | 91 filas vienen con el RFC tachado por la LFPDPPP y no son basura |
| Registro partido en dos líneas | el salto va dentro del campo entrecomillado; leer por líneas corrompe dos filas |
| Preámbulo de dos líneas | el 69-B lo trae y el Art. 69 no; el encabezado se busca, no se cuenta |
| windows-1252 | los archivos no son UTF-8 y los casos de `casos/` tampoco, a propósito |
| Fecha del preámbulo vs. del servidor | hay 17 días medidos de diferencia y la constancia cita la primera |
| Día de la semana en `Last-Modified` | si no cuadra con el número, PHP avanza al siguiente y desplaza la fecha hasta seis días |
| La cadena completa de deltas | el 69-B se publica cada uno o dos meses: sin el simulacro habría que esperar |

**Lo que no se prueba, y conviene saberlo:** el veredicto de `lote.php`
—en particular la regla de que Desvirtuado y Sentencia Favorable no son
hallazgo adverso, que es la más cara de equivocar— no se puede probar desde
aquí, porque vive en un archivo que empieza pidiendo sesión. Habría que sacar
esa función a `cron/lib/`, y eso es un cambio a código que hoy funciona.

## El simulacro

`casos/delta_mayo.csv` y `casos/delta_junio.csv` son una publicación del 69-B
fabricada a mano. De mayo a junio cambia exactamente esto:

- `BBB020202BBB` y `ÑAÑ030303CCC` pasan de **Presunto a Definitivo**
- `DDD040404DDD` **desaparece** del archivo
- `EEE050505EEE` **entra** como Presunto
- `AAA010101AAA` sigue igual, y `XXXXXXXXXXXX` sigue suprimido

Lo que se comprueba es que la primera carga se marca como línea base sin
generar ni una alerta, que la segunda detecta 1 alta, 2 cambios, 1 baja y 3
urgentes, que el historial de `BBB` queda con la fila vieja cerrada y la nueva
abierta, que el que desapareció se cierra en vez de borrarse, y que volver a
cargar el mismo archivo no duplica nada.

## Añadir un caso

Cuando algo se rompa en producción, el arreglo lleva una comprobación aquí.
Los archivos se llaman `NN_tema.php` y `correr.php` los carga en orden; dentro
solo hacen falta `comprobar()`, `comprobar_que()` y `omitir()`.

Los CSV de `casos/` están en windows-1252 con CRLF, como los del SAT. No se
escriben a mano: los genera `casos/generar.php`, que es determinista y se
puede volver a correr sin que cambie ni un byte.

```
php listas/pruebas/casos/generar.php listas/pruebas/casos
```
