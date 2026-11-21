<?php
define('APP_NAME', 'Joyería Onix');
define('MONEDA', '$/');

// Se pueden sobrescribir con variables de entorno (las usa la suite tests/).
define('DB_HOST', getenv('ONIX_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('ONIX_DB_PORT') ?: '3307');
define('DB_NAME', getenv('ONIX_DB_NAME') ?: 'joyeria_onix');
define('DB_USER', getenv('ONIX_DB_USER') ?: 'root');
define('DB_PASS', getenv('ONIX_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');
