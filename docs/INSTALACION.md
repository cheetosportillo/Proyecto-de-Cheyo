# Instalación (Bloques 1.2 y 1.3)

`schema.sql` es la fuente única de la estructura. El instalador web (`install.php`) la carga; no hay que importar nada a mano.

## XAMPP (esta computadora)
1. Copia el proyecto a `htdocs/` y abre `http://localhost/<carpeta>/`. Te lleva solo al instalador.
2. **Paso 1 · Base de datos**: deja «Mi computadora (XAMPP)», servidor `127.0.0.1`, el puerto de tu MariaDB (3306 o 3307),
   usuario `root` (clave vacía en XAMPP). Deja marcadas «Crear la base si no existe» y «Crear usuario restringido onix_app».
3. **Paso 2 · Cuenta de la Dueña**: nombre, usuario y contraseña (mín. 8). Al terminar se muestra **una sola vez** el código
   `ONIX-XXXX-XXXX-XXXX`: imprímelo o anótalo y guárdalo fuera de la tienda.

## Hosting compartido (InfinityFree)
1. En el panel del hosting crea la base de datos MySQL y anota servidor (`sqlXXX.infinityfree.com`), nombre (`if0_…`), usuario y clave.
2. Sube el proyecto por FTP / Administrador de archivos y abre la URL. Pide el **token de instalación**: ábrelo en
   `config/install.token` con el Administrador de archivos y pégalo.
3. Elige «Hosting»: las casillas de crear base y crear usuario quedan **desmarcadas** (ya vienen hechas). Si las marcas por error,
   la base existente se detecta y se omite sola; si la cuenta no tiene permisos de `CREATE USER/GRANT`, se omiten con un aviso.
4. Los triggers son «mejor esfuerzo»: si el hosting no los permite, solo se avisa y el sistema funciona igual.

Al terminar se crea `config/instalado.lock` y `install.php` queda inerte. Para reinstalar de cero: borra `config/config.local.php`
y `config/instalado.lock` **y** vacía la base (si la BD ya tiene usuarios, el instalador no vuelve a abrirse).

## Actualizar desde la 1.2 a la 1.3 (base que ya existe)
`schema.sql` solo tiene `CREATE TABLE IF NOT EXISTS`: crea las tablas nuevas pero **no agrega** las columnas nuevas de `usuarios`
(`email`, `reset_token_hash`, `reset_token_expira`). Hasta migrar, el sistema muestra «Falta actualizar la base de datos».
- **XAMPP / con consola** (cuenta `root`, no la de la app):
  `php tools/migrar_1_3.php --user=root --pass=` (agrega `--port=3307` si aplica). Es repetible y también da permisos a `onix_app`
  sobre las tablas nuevas. Repítelo con `--db=joyeria_onix_test` si quieres la base de pruebas migrada sin recrearla.
- **Hosting sin consola (phpMyAdmin)**: 1) pestaña Importar → `schema.sql` (crea tablas nuevas y semillas, no toca datos);
  2) pestaña SQL, ejecuta (MariaDB):
  ```sql
  ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS `email` varchar(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `reset_token_hash` varchar(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `reset_token_expira` datetime DEFAULT NULL;
  ```
  En hosting la app usa la cuenta que dio el panel (con permisos completos): no hay que otorgar nada más.
Una instalación nueva no necesita nada de esto: `install.php` ya carga todo.

## Bloque 1.3: usuarios, roles, correo y recuperación
- **Usuarios** (menú, solo Dueña): crear (se genera una *clave temporal* que se ve una sola vez y obliga a cambiarla al entrar), editar
  (incluye correo), activar/desactivar, restablecer contraseña y editar los permisos del rol Cajero. La Dueña siempre tiene acceso total;
  `usuarios.gestionar` y `configuracion.gestionar` son solo de la Dueña.
- **Configuración** (menú, solo Dueña): nombre, teléfono, dirección y mensaje del ticket, y el SMTP con botón de prueba.
  La contraseña SMTP se guarda **cifrada** con `config/app.key` (se crea sola; respáldala junto con la base o tendrás que volver a escribirla).
  **InfinityFree** solo deja salir por el puerto **587** (STARTTLS); `mail()` no sirve allí. Gmail necesita «contraseña de aplicación».
- **«¿Olvidaste tu contraseña?»**: *con internet* → código de 8 caracteres al correo (vale 15 min); *sin internet* → solo la Dueña, con su
  llave maestra `ONIX-XXXX-XXXX-XXXX` (se rota y se muestra la nueva una vez; queda en bitácora). Ambos con límite de 5 intentos / 15 min.
  Un empleado sin internet pide a la Dueña «Restablecer contraseña» en Usuarios.
- Una Dueña creada desde Usuarios recibe su propia llave maestra (se muestra una vez).

## Bloque 1.4: respaldos, bitácora, llave maestra y calidad de vida
No cambia la base de datos: **no hay migración** (los permisos `respaldos.gestionar` y `bitacora.ver` son solo de la Dueña por código).
- **Respaldos** (menú, solo Dueña): «Generar y descargar» crea un `.sql` en `backups/` (carpeta ignorada por git y con `.htaccess`
  «Require all denied») y lo descarga; la lista permite bajar los anteriores y marca los incompletos. Es PHP puro vía PDO (sin `mysqldump`),
  con instantánea consistente: se puede seguir vendiendo. Cada generación/descarga queda en bitácora.
  Contiene datos sensibles (hashes, correos, ventas): guárdalo **fuera de la tienda**. Respalda aparte `config/app.key` (cifra la clave SMTP).
  Si puedes, apunta `ONIX_BACKUP_DIR` a una carpeta fuera de la raíz web.
- **Restaurar**: el `.sql` incluye `DROP TABLE IF EXISTS` + `CREATE TABLE` + datos, así que *reemplaza* lo que haya. En phpMyAdmin: elige la base →
  Importar. Con consola: `mysql -u root joyeria_onix < backups/respaldo_….sql`. **No incluye triggers** y `DROP TABLE` borra los que tenía la tabla: después de restaurar ejecuta
  `php tools/instalar_triggers.php --user=root --pass=` (solo si usas los triggers; son «mejor esfuerzo»).
- **Usuario `onix_backup` (opcional, XAMPP)**: `php tools/crear_usuario_backup.php --user=root --pass=` crea una cuenta de solo lectura y guarda sus
  credenciales en `config/config.local.php`; las instalaciones nuevas en XAMPP la crean solas. En hosting compartido se omite con aviso y se usa la cuenta de la app.
- **Bitácora** (menú, solo Dueña): filtros por fecha, usuario y acción, 50 por página y «Exportar CSV» (hasta 50 000 filas; celdas que empiezan con
  `= + - @` se neutralizan). Es solo lectura.
- **Llave maestra**: en «Cambiar contraseña» (solo Dueña) → «Regenerar llave maestra»: pide la contraseña, anula la anterior y muestra la nueva una vez (imprimible).
- **Emergencia**: `php tools/reset_duena.php [--usuario=NOMBRE]` (solo consola) activa la cuenta, da una clave temporal y una llave nueva, y lo deja en bitácora.
- **Calidad de vida**: ojito en todos los campos de contraseña; aviso 2 min antes de que venza la sesión (30 min, sin cambios) con «Seguir trabajando».

## Verificar que Parte 1 y Parte 2 siguen compatibles
```
php tools/verificar_instalador.php                 # sin BD: compara schema.sql con el volcado de la Parte 2, divisor SQL, código de recuperación
php tools/verificar_instalador.php --bd            # recrea joyeria_onix_test desde schema.sql y revisa uuid/updated_at/usuarios
php tests/run.php                                  # suite de la Parte 2 sobre esa BD
php tools/verificar_instalador.php --bd --triggers # segunda pasada: con los 5 triggers activos
php tests/run.php
```
(Variables de entorno como en `tests/`: `ONIX_DB_PORT=3307`, `ONIX_DB_USER`, `ONIX_DB_PASS`. En Windows: `C:\xampp\php\php.exe …`.)

## Bloque 2: app instalable y ventas sin internet (PWA híbrida)
**No cambia la base de datos** (usa `uuid_operacion` y `updated_at` que ya existen). No hay migración.

**Instalar.** Chrome/Edge/Android ofrecen «Instalar» (o el botón «Instalar app» del menú) cuando el sitio va por **https://** o
**localhost**. En `http://192.168.x.x` (red local sin https) el navegador no permite Service Worker ni instalar: el POS sigue guardando
ventas sin internet en IndexedDB, pero no abre sin conexión al recargar. InfinityFree da https gratis: úsalo ahí.

**Qué hay sin internet.**
- *Pantalla del POS*: el Service Worker (`sw.js`) guarda una copia **sin historial de ventas** (`?c=venta&a=pos`) cada vez que el POS carga con
  internet; se borra al cerrar sesión y caduca a los 7 días. Estáticos (Bootstrap, iconos, SweetAlert2, JS/CSS propios): Cache First. Nada de `api()`
  ni de respuestas `no-store` se cachea jamás. Al cambiar `sw.js` o su lista de precache, sube `VERSION`.
- *Catálogo*: copia en IndexedDB de lo vendible (activo y con stock), se actualiza **por cambios** con `updated_at` (también si cambia la categoría)
  cada ≤3 min y al volver la red. El cursor guarda «ahora − 5 s» porque `TIMESTAMP` tiene resolución de segundos.
- *Cobrar sin conexión* (red caída **o** servidor inalcanzable **o** sesión vencida): la venta queda en la cola local con su `uuid_operacion`,
  se imprime un comprobante **provisional** `TEMP-XXXXXX` y el stock local se reserva para no revender lo mismo. El ticket oficial se asigna al sincronizar.
- *Indicador* (menú lateral y barra móvil): «En línea» (verde) / «Sin conexión · N pendientes» (amarillo) + botón de lista de la cola y de sincronizar ya.

**Sincronización** (al volver la red, cada 20 s si hay pendientes, al volver a la pestaña o a mano): una a una, **en orden FIFO**, a `venta/sincronizar`
(idempotente: repetir el mismo `uuid_operacion` devuelve la misma venta como «duplicada»). Antes de enviar se comprueba la sesión (sin renovarla) y se renueva el CSRF.

| Respuesta | Qué pasa con la venta |
|---|---|
| OK / duplicada | Queda «Sincronizada» con su ticket real (se muestra 3 días). |
| Red caída, 5xx, respuesta rara | Sigue «Pendiente»; reintenta con espera creciente (15 s → 2 min). No se salta el orden. |
| 401 / 403 / 419 sin remedio | **Pausa** y aviso («inicia sesión de nuevo»). No se borra nada; se reanuda sola al volver a tener sesión. |
| Conflicto de negocio (caja cerrada, stock 0, producto inactivo, **precio cambiado**) | **«Requiere revisión»** con el motivo exacto del servidor. Se puede *Reintentar* (p. ej. tras abrir la caja o ajustar inventario) o *Descartar* (solo quien puede anular ventas, con motivo, solo con internet, y queda en bitácora; si el servidor ya la tiene, no se descarta). |

Reglas de negocio que conviene saber: la venta se registra con los **precios de hoy**; si el total difiere de lo que se le cobró al cliente offline
no se registra (conflicto `precio_cambiado`). La venta conserva su **hora real** (en hora de El Salvador), sin pasar de «ahora» ni quedar antes de la apertura
de la caja donde cae; el Kardex registra la hora de sincronización. Cada venta se sincroniza con el **usuario que la hizo**: si otra persona inicia sesión en ese
navegador, la cola queda en pausa. Al **cerrar sesión con ventas pendientes** se muestra una alerta fuerte (sincronizar / cerrar de todos modos / cancelar).

**Zona horaria.** PHP (`config.php`), la sesión de MariaDB (`Database::get()`, `Instalador::conectar`, `Respaldo`) y el JS (`America/El_Salvador`)
usan siempre UTC-6. `Database::zonaHoraria()` lo verifica y `tests/SincronizacionTest.php` lo comprueba.

**Pruebas.** `php tests/run.php` (incluye `SincronizacionTest`: catálogo incremental y paginado, venta offline idempotente, conflictos, descarte, zona horaria,
manifest, íconos y `sw.js`). Opcional, de navegador real: `tests/pwa-navegador/` (ver su `LEEME.md`).

## Para los próximos bloques
- **Reporte de «ventas offline» para la Dueña**: la bitácora ya guarda `VENTA_OFFLINE_SINCRONIZADA` / `VENTA_OFFLINE_DESCARTADA`; falta una vista.
- **Compras offline** y **Background Sync** (subir con la app cerrada): fuera del alcance del Bloque 2 (solo POS).
