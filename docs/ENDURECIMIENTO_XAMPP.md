# Endurecimiento básico del equipo de la tienda (XAMPP / Windows)

Se hace UNA vez al instalar. Objetivo: que desde el navegador nadie llegue a phpMyAdmin,
a `/.git`, a respaldos ni a archivos de configuración.

## 1. Bloquear phpMyAdmin y los paneles de XAMPP (ERR-17)
1. Abre `C:\xampp\apache\conf\extra\httpd-xampp.conf` con el Bloc de notas (como administrador).
2. Busca el bloque que empieza con `<LocationMatch "^/(?i:(?:xampp|security|licenses|phpmyadmin|...`
   y cambia `Require local` por **`Require all denied`**.
3. Busca `<Directory "C:/xampp/phpMyAdmin">` y cambia también `Require local` por `Require all denied`.
   (El texto exacto puede variar un poco según la versión de XAMPP.)
4. Reinicia Apache desde el Panel de XAMPP.
5. Verifica: `http://localhost/phpmyadmin` debe responder **403 Forbidden**.

> Mantenimiento técnico: si un técnico necesita phpMyAdmin, vuelve a poner `Require local`
> solo el tiempo necesario, haz respaldo antes y restablece `Require all denied` al terminar.

## 2. Escuchar solo en este equipo (si hay UNA sola computadora)
En `C:\xampp\apache\conf\httpd.conf` cambia `Listen 80` por `Listen 127.0.0.1:80`.
Si hay varias terminales en red local, NO lo cambies y usa el firewall de Windows para
permitir el puerto 80 solo a la red de la tienda.

## 3. Archivos del proyecto
* `.htaccess` (raíz) ya bloquea: `/.git`, `*.md`, `*.sql`, `*.patch`, `*.bak`, `*.log`, `*.bat`, `.env`…
* `app/`, `config/`, `tests/`, `tools/` y `docs/` tienen su propio `.htaccess` (`Require all denied`).
* Requiere `AllowOverride All` (es el valor por defecto de XAMPP en `htdocs`).
* Credenciales: copia `config/config.local.php.example` a `config/config.local.php`. Ese archivo NO se sube a git.

## 4. Comprobación rápida (todas deben dar 403 o 404)
```
http://localhost/joyeria_onix/.git/config
http://localhost/joyeria_onix/joyeria_onix.sql
http://localhost/joyeria_onix/config/config.php
http://localhost/joyeria_onix/tests/run.php
http://localhost/phpmyadmin
```

## 5. Hosting compartido (cPanel)
* Quita o protege phpMyAdmin con restricción por IP desde el panel del proveedor.
* Activa HTTPS (SSL). La sesión pasará a cookie `Secure` automáticamente.
