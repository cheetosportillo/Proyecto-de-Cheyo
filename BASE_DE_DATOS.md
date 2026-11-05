# Base de datos: `joyeria_onix`

Motor **InnoDB** (necesario para transacciones y llaves foráneas) y cotejamiento **utf8mb4_general_ci**.
Los nombres de tablas y columnas deben ser **exactamente** estos (el código PHP los usa tal cual).

## 1. usuarios
| Columna | Tipo | Notas |
|---|---|---|
| id_usuario | INT, PK, AUTO_INCREMENT | |
| nombre | VARCHAR(100) | NOT NULL |
| usuario | VARCHAR(30) | NOT NULL, **UNIQUE** |
| password | VARCHAR(255) | NOT NULL (guarda el hash, nunca texto plano) |
| rol | ENUM('duena','cajero') | NOT NULL, default 'cajero' |
| estado | TINYINT(1) | NOT NULL, default 1 (1 activo, 0 inactivo) |
| created_at | TIMESTAMP | default CURRENT_TIMESTAMP |

## 2. categorias
| Columna | Tipo | Notas |
|---|---|---|
| id_categoria | INT, PK, AUTO_INCREMENT | |
| nombre | VARCHAR(50) | NOT NULL, UNIQUE |
| stock_minimo | INT | NOT NULL, default 0 (0 = sin alerta) |

Registros iniciales: `Gemas`, `Plata`, `Cobre`, `Oro`.

## 3. productos
| Columna | Tipo | Notas |
|---|---|---|
| id_producto | INT, PK, AUTO_INCREMENT | |
| codigo | VARCHAR(30) | NOT NULL, **UNIQUE** |
| nombre | VARCHAR(120) | NOT NULL |
| descripcion | VARCHAR(255) | NULL permitido |
| id_categoria | INT | NOT NULL, FK → categorias.id_categoria |
| peso | DECIMAL(10,3) | NOT NULL, default 0 |
| unidad_peso | ENUM('gramos','kilates') | NOT NULL, default 'gramos' |
| costo | DECIMAL(10,2) | NOT NULL, default 0 |
| precio_venta | DECIMAL(10,2) | NOT NULL, default 0 |
| stock | INT | NOT NULL, default 0 |
| estado | TINYINT(1) | NOT NULL, default 1 (0 = eliminado lógicamente) |
| created_at | TIMESTAMP | default CURRENT_TIMESTAMP |

## 4. proveedores
| Columna | Tipo | Notas |
|---|---|---|
| id_proveedor | INT, PK, AUTO_INCREMENT | |
| nombre | VARCHAR(120) | NOT NULL |
| telefono | VARCHAR(30) | NULL permitido |
| correo | VARCHAR(120) | NULL permitido |
| direccion | VARCHAR(255) | NULL permitido |
| estado | TINYINT(1) | NOT NULL, default 1 |

## 5. compras
| Columna | Tipo | Notas |
|---|---|---|
| id_compra | INT, PK, AUTO_INCREMENT | |
| id_proveedor | INT | NOT NULL, FK → proveedores.id_proveedor |
| id_usuario | INT | NOT NULL, FK → usuarios.id_usuario |
| fecha | DATETIME | NOT NULL, default CURRENT_TIMESTAMP |
| total | DECIMAL(12,2) | NOT NULL |
| observacion | VARCHAR(255) | NULL permitido |

## 6. detalle_compra
| Columna | Tipo | Notas |
|---|---|---|
| id_detalle_compra | INT, PK, AUTO_INCREMENT | |
| id_compra | INT | NOT NULL, FK → compras.id_compra |
| id_producto | INT | NOT NULL, FK → productos.id_producto |
| cantidad | INT | NOT NULL |
| costo_unitario | DECIMAL(10,2) | NOT NULL |
| subtotal | DECIMAL(12,2) | NOT NULL |

## 7. ventas
| Columna | Tipo | Notas |
|---|---|---|
| id_venta | INT, PK, AUTO_INCREMENT | |
| id_usuario | INT | NOT NULL, FK → usuarios.id_usuario |
| fecha | DATETIME | NOT NULL, default CURRENT_TIMESTAMP |
| total | DECIMAL(12,2) | NOT NULL |
| efectivo | DECIMAL(12,2) | NOT NULL |
| cambio | DECIMAL(12,2) | NOT NULL |

## 8. detalle_venta
| Columna | Tipo | Notas |
|---|---|---|
| id_detalle_venta | INT, PK, AUTO_INCREMENT | |
| id_venta | INT | NOT NULL, FK → ventas.id_venta |
| id_producto | INT | NOT NULL, FK → productos.id_producto |
| cantidad | INT | NOT NULL |
| precio_unitario | DECIMAL(10,2) | NOT NULL (precio al momento de la venta) |
| peso | DECIMAL(10,3) | NOT NULL (peso al momento de la venta) |
| unidad_peso | ENUM('gramos','kilates') | NOT NULL |
| subtotal | DECIMAL(12,2) | NOT NULL |

## Relaciones
categorias 1—N productos · proveedores 1—N compras · compras 1—N detalle_compra · ventas 1—N detalle_venta ·
productos 1—N detalle_compra / detalle_venta · usuarios 1—N compras / ventas.

## Para el Kardex (más adelante)
Las tablas `detalle_compra` (entradas) y `detalle_venta` (salidas) ya guardan cantidad, costo/precio y peso por movimiento,
así que el Kardex se podrá construir con una tabla `kardex` nueva sin cambiar lo existente.
