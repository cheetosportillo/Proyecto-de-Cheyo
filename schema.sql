-- =====================================================================
-- Joyería Onix · schema.sql  (Bloque 1.2)  ·  versión de esquema 1.2.0
-- Fuente única de verdad de la estructura de la base de datos.
--
--  * Base EXACTA: joyeria_onix (2).sql de la Parte 2 (mismos nombres de tablas,
--    columnas, índices y claves foráneas). tests/run.php debe seguir al 100 %.
--  * Solo CREATE TABLE IF NOT EXISTS (0 ALTER TABLE): es seguro re-ejecutarlo.
--  * Sin datos de personas: la cuenta de la Dueña la crea el instalador web.
--  * Sin CREATE DATABASE / USE / GRANT: eso lo decide el instalador según el
--    entorno (XAMPP vs. hosting compartido). Se puede importar tal cual en
--    phpMyAdmin dentro de una base ya creada.
--  * Los triggers viven aparte (schema_triggers.sql): son opcionales porque
--    algunos hosting compartidos no permiten crearlos.
--
-- Preparado para el modo híbrido (PWA + sincronización):
--  * uuid_operacion (char(36) NULL UNIQUE) en ventas, compras y caja_movimientos.
--  * updated_at (ON UPDATE CURRENT_TIMESTAMP) en productos, categorias y
--    proveedores para cachear / sincronizar el catálogo en IndexedDB.
--
-- Formato: un `;` al final de línea cierra la sentencia (lo entiende el
-- instalador y también mysql/phpMyAdmin). No pongas `;` al final de un
-- comentario ni dentro de un texto que termine la línea.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- usuarios
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id_usuario` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `rol` enum('duena','cajero') NOT NULL DEFAULT 'cajero',
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `codigo_recuperacion_hash` varchar(255) DEFAULT NULL,
  `debe_cambiar_password` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `usuario` (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Catálogo (con updated_at para sincronización)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categorias` (
  `id_categoria` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `unidad_peso` enum('gramos','kilates') NOT NULL DEFAULT 'gramos',
  `stock_minimo` int(11) NOT NULL DEFAULT 0,
  `peso_minimo` decimal(12,3) NOT NULL DEFAULT 0.000,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_categoria`),
  UNIQUE KEY `nombre` (`nombre`),
  KEY `idx_categorias_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `proveedores` (
  `id_proveedor` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_proveedor`),
  KEY `idx_proveedores_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `productos` (
  `id_producto` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `id_categoria` int(11) NOT NULL,
  `peso` decimal(12,3) NOT NULL DEFAULT 0.000,
  `costo` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `precio_venta` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock` int(11) NOT NULL DEFAULT 0,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_producto`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `fk_productos_categoria` (`id_categoria`),
  KEY `idx_productos_updated` (`updated_at`),
  CONSTRAINT `fk_productos_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id_categoria`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Caja (una sola caja ABIERTA a la vez: abierta_unica es UNIQUE y NULL al cerrar)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cajas` (
  `id_caja` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario_apertura` int(11) NOT NULL,
  `fecha_apertura` datetime NOT NULL DEFAULT current_timestamp(),
  `monto_inicial` decimal(12,2) NOT NULL DEFAULT 0.00,
  `estado` enum('ABIERTA','CERRADA') NOT NULL DEFAULT 'ABIERTA',
  `fecha_cierre` datetime DEFAULT NULL,
  `id_usuario_cierre` int(11) DEFAULT NULL,
  `efectivo_esperado` decimal(12,2) DEFAULT NULL,
  `efectivo_contado` decimal(12,2) DEFAULT NULL,
  `diferencia` decimal(12,2) DEFAULT NULL,
  `abierta_unica` tinyint(1) GENERATED ALWAYS AS (if(`estado` = 'ABIERTA',1,NULL)) STORED,
  PRIMARY KEY (`id_caja`),
  UNIQUE KEY `uk_una_caja_abierta` (`abierta_unica`),
  KEY `fk_cajas_usr_ap` (`id_usuario_apertura`),
  KEY `fk_cajas_usr_ci` (`id_usuario_cierre`),
  CONSTRAINT `fk_cajas_usr_ap` FOREIGN KEY (`id_usuario_apertura`) REFERENCES `usuarios` (`id_usuario`),
  CONSTRAINT `fk_cajas_usr_ci` FOREIGN KEY (`id_usuario_cierre`) REFERENCES `usuarios` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Ventas (uuid_operacion = idempotencia de la sincronización offline)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ventas` (
  `id_venta` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `efectivo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cambio` decimal(10,2) NOT NULL DEFAULT 0.00,
  `uuid_operacion` char(36) DEFAULT NULL,
  `numero_ticket` int(11) DEFAULT NULL,
  `id_caja` int(11) DEFAULT NULL,
  `estado` enum('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
  `motivo_anulacion` varchar(255) DEFAULT NULL,
  `id_usuario_anula` int(11) DEFAULT NULL,
  `fecha_anulacion` datetime DEFAULT NULL,
  `impresiones` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_venta`),
  UNIQUE KEY `uk_ventas_uuid` (`uuid_operacion`),
  UNIQUE KEY `uk_ventas_ticket` (`numero_ticket`),
  KEY `fk_ventas_usuario` (`id_usuario`),
  KEY `idx_ventas_fecha` (`fecha`),
  KEY `idx_ventas_caja` (`id_caja`),
  CONSTRAINT `fk_ventas_caja` FOREIGN KEY (`id_caja`) REFERENCES `cajas` (`id_caja`),
  CONSTRAINT `fk_ventas_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `detalle_venta` (
  `id_detalle_venta` int(11) NOT NULL AUTO_INCREMENT,
  `id_venta` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `codigo` varchar(50) NOT NULL DEFAULT '',
  `nombre` varchar(150) NOT NULL DEFAULT '',
  `cantidad` int(11) NOT NULL DEFAULT 1,
  `precio_unitario` decimal(10,2) NOT NULL DEFAULT 0.00,
  `peso` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unidad_peso` enum('gramos','kilates') NOT NULL DEFAULT 'gramos',
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `costo_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `costo_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id_detalle_venta`),
  KEY `fk_dventa_venta` (`id_venta`),
  KEY `fk_dventa_producto` (`id_producto`),
  CONSTRAINT `fk_dventa_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON UPDATE CASCADE,
  CONSTRAINT `fk_dventa_venta` FOREIGN KEY (`id_venta`) REFERENCES `ventas` (`id_venta`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Movimientos de caja (uuid_operacion NULL: los inserts actuales no lo envían)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `caja_movimientos` (
  `id_movimiento` int(11) NOT NULL AUTO_INCREMENT,
  `id_caja` int(11) NOT NULL,
  `tipo` enum('INGRESO','RETIRO','GASTO','DEVOLUCION') NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `motivo` varchar(255) NOT NULL,
  `id_venta` int(11) DEFAULT NULL,
  `id_usuario` int(11) NOT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `uuid_operacion` char(36) DEFAULT NULL,
  PRIMARY KEY (`id_movimiento`),
  UNIQUE KEY `uk_mov_uuid` (`uuid_operacion`),
  KEY `idx_mov_caja` (`id_caja`),
  KEY `fk_mov_venta` (`id_venta`),
  KEY `fk_mov_usr` (`id_usuario`),
  CONSTRAINT `fk_mov_caja` FOREIGN KEY (`id_caja`) REFERENCES `cajas` (`id_caja`),
  CONSTRAINT `fk_mov_usr` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`),
  CONSTRAINT `fk_mov_venta` FOREIGN KEY (`id_venta`) REFERENCES `ventas` (`id_venta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Compras
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `compras` (
  `id_compra` int(11) NOT NULL AUTO_INCREMENT,
  `id_proveedor` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `observacion` text DEFAULT NULL,
  `uuid_operacion` char(36) DEFAULT NULL,
  `numero_factura` varchar(40) DEFAULT NULL,
  `estado` enum('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
  `motivo_anulacion` varchar(255) DEFAULT NULL,
  `id_usuario_anula` int(11) DEFAULT NULL,
  `fecha_anulacion` datetime DEFAULT NULL,
  PRIMARY KEY (`id_compra`),
  UNIQUE KEY `uk_compras_uuid` (`uuid_operacion`),
  UNIQUE KEY `uk_compras_factura` (`id_proveedor`,`numero_factura`),
  KEY `fk_compras_usuario` (`id_usuario`),
  CONSTRAINT `fk_compras_proveedor` FOREIGN KEY (`id_proveedor`) REFERENCES `proveedores` (`id_proveedor`) ON UPDATE CASCADE,
  CONSTRAINT `fk_compras_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `detalle_compra` (
  `id_detalle_compra` int(11) NOT NULL AUTO_INCREMENT,
  `id_compra` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 1,
  `costo_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id_detalle_compra`),
  KEY `fk_dcompra_compra` (`id_compra`),
  KEY `fk_dcompra_producto` (`id_producto`),
  CONSTRAINT `fk_dcompra_compra` FOREIGN KEY (`id_compra`) REFERENCES `compras` (`id_compra`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dcompra_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Kardex (CPP) y bitácora: solo se agregan filas (ver schema_triggers.sql)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kardex` (
  `id_kardex` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_producto` int(11) NOT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `tipo` enum('SALDO_INICIAL','ENTRADA','AJUSTE_ENTRADA','ANULACION_VENTA','SALIDA','AJUSTE_SALIDA','ANULACION_COMPRA') NOT NULL,
  `cantidad` int(11) NOT NULL,
  `peso_mov` decimal(14,3) NOT NULL DEFAULT 0.000,
  `unidad_peso` enum('gramos','kilates') NOT NULL,
  `costo_unitario` decimal(14,4) NOT NULL,
  `valor_total` decimal(14,2) NOT NULL,
  `saldo_cantidad` int(11) NOT NULL,
  `saldo_peso` decimal(14,3) NOT NULL,
  `saldo_monetario` decimal(14,2) NOT NULL,
  `cpp` decimal(14,4) NOT NULL,
  `ref_tipo` varchar(20) DEFAULT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  PRIMARY KEY (`id_kardex`),
  KEY `idx_kardex_prod` (`id_producto`,`id_kardex`),
  KEY `idx_kardex_fecha` (`fecha`),
  KEY `fk_kardex_usr` (`id_usuario`),
  CONSTRAINT `fk_kardex_prod` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`),
  CONSTRAINT `fk_kardex_usr` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bitacora` (
  `id_bitacora` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `id_usuario` int(11) DEFAULT NULL,
  `accion` varchar(60) NOT NULL,
  `detalle` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id_bitacora`),
  KEY `idx_bit_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Secuencias (ticket correlativo sin huecos) y control de versión del esquema
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `secuencias` (
  `nombre` varchar(30) NOT NULL,
  `valor` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` varchar(40) NOT NULL,
  `descripcion` varchar(255) NOT NULL DEFAULT '',
  `aplicada_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Semillas mínimas (idempotentes)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `secuencias` (`nombre`, `valor`) VALUES ('ticket', 0);

INSERT IGNORE INTO `schema_migrations` (`version`, `descripcion`) VALUES ('1.2.0', 'Esquema base híbrido: uuid_operacion en caja_movimientos, updated_at en catálogo, recuperación de la Dueña');
