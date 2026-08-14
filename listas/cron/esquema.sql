-- ============================================================================
--  Esquema de la herramienta de listas del SAT.
--  MySQL / MariaDB, InnoDB, utf8mb4.
--
--  Se aplica con:  php listas/cron/migrar.php
--  Es idempotente: se puede volver a correr sin romper nada.
-- ============================================================================

-- ---------------------------------------------------------------- snapshots
-- Un registro por cada descarga que trajo contenido distinto.
CREATE TABLE IF NOT EXISTS snapshots (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lista          VARCHAR(40)     NOT NULL,           -- art69b.presuntos, art69.firmes…
  url            VARCHAR(500)    NOT NULL,
  sha256         CHAR(64)        NOT NULL,
  bytes          BIGINT UNSIGNED NOT NULL,
  -- La fecha que vale es la del preámbulo del CSV, no la del servidor:
  -- se han medido 17 días de diferencia entre una y otra.
  fecha_archivo  DATE            NULL,
  fecha_servidor DATETIME        NULL,
  filas          INT UNSIGNED    NULL,
  filas_validas  INT UNSIGNED    NULL,
  ruta_archivo   VARCHAR(300)    NOT NULL,           -- copia cruda archivada
  descargado_en  DATETIME        NOT NULL,
  procesado_en   DATETIME        NULL,
  -- La primera carga de una lista no es noticia: es el punto de partida. Sus
  -- altas no deben contarse como alertas ni avisarse por correo.
  linea_base     TINYINT(1)      NOT NULL DEFAULT 0,
  -- Cuándo se avisó por correo de que el SAT publicó esta versión. NULL =
  -- pendiente. Es lo que dispara el «vuelve a barrer la cartera».
  avisado_en     DATETIME        NULL DEFAULT NULL,
  PRIMARY KEY (id),
  -- Idempotencia: el mismo archivo no se procesa dos veces.
  UNIQUE KEY uk_lista_sha (lista, sha256),
  KEY ix_lista_fecha (lista, descargado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ estatus
-- Historial tipo SCD 2: cada fila vale desde una fecha hasta otra.
-- valido_hasta NULL = es lo vigente hoy.
--
-- Esto es lo que permite responder "a la fecha X este RFC no aparecía en el
-- archivo", que es el entregable que el contador necesita para expediente.
--
--  La clave NO es el RFC. Medido sobre el listado completo: 82 RFC aparecen
--  dos veces porque el mismo contribuyente puede pasar por el procedimiento
--  69-B más de una vez. ACJ160118CG9 ganó sentencia favorable en 2018 y volvió
--  a ser presunto en 2026. Con la clave puesta en el RFC, la alerta nueva
--  pisaría el expediente viejo.
--
--  La clave real es (RFC + oficio global de presunción): cero colisiones en
--  los tres archivos, ninguna fila sin oficio, y es estable — ese número no
--  cambia cuando el procedimiento avanza de presunto a definitivo, que es
--  justo lo que permite detectarlo como cambio y no como alta nueva.
--
CREATE TABLE IF NOT EXISTS estatus (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rfc            VARCHAR(20)     NOT NULL,
  lista          VARCHAR(40)     NOT NULL,
  -- sha256 recortado del oficio de presunción; '' en Art. 69, que no lo tiene
  proc_hash      CHAR(16)        NOT NULL DEFAULT '',
  proc_texto     VARCHAR(200)    NULL,
  situacion      VARCHAR(60)     NULL,               -- 69-B: Presunto, Definitivo…
  supuesto       VARCHAR(80)     NULL,               -- art69: FIRMES, NO LOCALIZADOS…
  nombre         VARCHAR(400)    NULL,
  tipo_persona   CHAR(1)         NULL,               -- M moral · F física
  entidad        VARCHAR(80)     NULL,
  -- El SAT tacha algunos registros por la LFPDPPP: llegan con RFC XXXXXXXXXXXX.
  -- No son basura, se conservan marcados.
  suprimido      TINYINT(1)      NOT NULL DEFAULT 0,
  datos          LONGTEXT        NULL,               -- fila cruda en JSON, para evidencia
  valido_desde   DATE            NOT NULL,
  valido_hasta   DATE            NULL,
  -- Truco para que la base garantice "como mucho una fila abierta por
  -- (rfc, lista)": vale 1 mientras está vigente y NULL cuando se cierra, y en
  -- MySQL los NULL no chocan entre sí en un índice único.
  vigente        TINYINT(1)      NULL DEFAULT 1,
  snapshot_desde BIGINT UNSIGNED NOT NULL,
  snapshot_hasta BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_vigente (rfc, lista, proc_hash, vigente),
  KEY ix_rfc (rfc),
  KEY ix_lista_situacion (lista, situacion, vigente),
  KEY ix_tipo (tipo_persona, vigente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ eventos
-- El delta. Es el producto: enterarse el mismo día de que un RFC entró como
-- presunto, porque el plazo para desvirtuar son 15 días hábiles.
CREATE TABLE IF NOT EXISTS eventos (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rfc                VARCHAR(20)     NOT NULL,
  lista              VARCHAR(40)     NOT NULL,
  proc_hash          CHAR(16)        NOT NULL DEFAULT '',
  tipo               ENUM('alta','cambio','baja') NOT NULL,
  situacion_anterior VARCHAR(60)     NULL,
  situacion_nueva    VARCHAR(60)     NULL,
  nombre             VARCHAR(400)    NULL,
  tipo_persona       CHAR(1)         NULL,
  -- 2 = urgente (entra como presunto, o pasa a definitivo)
  -- 1 = relevante · 0 = informativo
  prioridad          TINYINT         NOT NULL DEFAULT 0,
  detectado_en       DATETIME        NOT NULL,
  -- Cuándo se avisó por correo. NULL = pendiente. Evita repetir el aviso y,
  -- si el envío falla, deja que el siguiente intento lo recoja.
  avisado_en         DATETIME        NULL DEFAULT NULL,
  snapshot_id        BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY ix_detectado (detectado_en),
  KEY ix_rfc (rfc),
  KEY ix_prioridad (prioridad, detectado_en),
  KEY ix_lista_tipo (lista, tipo, detectado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- bitácora
-- Quién consultó qué y cuándo. Las listas traen personas físicas: son datos
-- personales y el registro de acceso es exigible.
CREATE TABLE IF NOT EXISTS bitacora (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario        VARCHAR(60)     NULL,
  ip             VARBINARY(16)   NULL,
  origen         VARCHAR(20)     NOT NULL,           -- web · api · lote · cron
  rfc_consultado VARCHAR(20)     NULL,
  cantidad       INT UNSIGNED    NOT NULL DEFAULT 1, -- para consultas por lote
  resultado      VARCHAR(40)     NULL,
  consultado_en  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_fecha (consultado_en),
  KEY ix_usuario (usuario, consultado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ ingesta
-- Última corrida por lista. Sirve para la alerta de "esta lista lleva N días
-- sin cambiar", que suele significar que el SAT movió la URL.
CREATE TABLE IF NOT EXISTS ingestas (
  lista            VARCHAR(40) NOT NULL,
  ultimo_intento   DATETIME    NULL,
  ultimo_exito     DATETIME    NULL,
  ultimo_cambio    DATETIME    NULL,
  ultimo_sha256    CHAR(64)    NULL,
  ultimo_error     VARCHAR(400) NULL,
  PRIMARY KEY (lista)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
