# Joyería Onix – Sistema de ventas (PHP + MySQL + MVC + Bootstrap 5)

## Instalación (XAMPP / WAMP / Laragon)
1. Copia la carpeta `joyeria_onix` dentro de `htdocs` (XAMPP) o `www` (WAMP).
2. En phpMyAdmin crea la base `joyeria_onix` y sus tablas según `BASE_DE_DATOS.md`.
3. Revisa los datos de conexión en `config/config.php`.
4. Abre `http://localhost/joyeria_onix/crear_admin.php`, crea la cuenta de la dueña y **borra ese archivo**.
5. Entra a `http://localhost/joyeria_onix/` e inicia sesión.

Requiere PHP 8.0+ e internet (Bootstrap, iconos y SweetAlert se cargan por CDN).

## Estructura MVC
- `index.php` → enrutador (`?c=controlador&a=accion`)
- `app/controllers` → lógica, permisos y respuestas JSON
- `app/models` → consultas PDO (sentencias preparadas)
- `app/views` → HTML + Bootstrap
- `public/js` → JavaScript (caja, compras, formularios) · `public/css` → estilos

## Roles
- **Dueña**: dashboard, ventas, inventario (CRUD), compras/proveedores, usuarios.
- **Cajero**: dashboard, caja (y sus tickets) e inventario solo de consulta.
