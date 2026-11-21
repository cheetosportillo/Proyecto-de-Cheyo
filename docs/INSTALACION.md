# Instalación (Bloque 1.2)

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

## Verificar que Parte 1 y Parte 2 siguen compatibles
```
php tools/verificar_instalador.php                 # sin BD: compara schema.sql con el volcado de la Parte 2, divisor SQL, código de recuperación
php tools/verificar_instalador.php --bd            # recrea joyeria_onix_test desde schema.sql y revisa uuid/updated_at/usuarios
php tests/run.php                                  # suite de la Parte 2 sobre esa BD
php tools/verificar_instalador.php --bd --triggers # segunda pasada: con los 5 triggers activos
php tests/run.php
```
(Variables de entorno como en `tests/`: `ONIX_DB_PORT=3307`, `ONIX_DB_USER`, `ONIX_DB_PASS`. En Windows: `C:\xampp\php\php.exe …`.)

## Para los próximos bloques
- **Recuperación de la Dueña (1.3)**: usar `Recuperacion::verificar($codigo, $usuario['codigo_recuperacion_hash'])`, con límite de intentos;
  al usarlo, rotar el código (generar otro y mostrarlo una vez) y poner `debe_cambiar_password = 1`.
- **Sincronización del catálogo (PWA)**: pedir `updated_at >= cursor` (con `>=` y restando 1 s: `TIMESTAMP` tiene resolución de segundos).
  El catálogo usa `estado` (no se borra), así que las bajas también viajan en `updated_at`.
- **Ventas offline**: `trg_ventas_caja_abierta` y `Venta::crear` exigen caja abierta *al sincronizar*. Hay que decidir qué hacer con una
  venta hecha offline que llega con la caja ya cerrada (p. ej. asignarla a la caja abierta actual o pedir reabrir).
