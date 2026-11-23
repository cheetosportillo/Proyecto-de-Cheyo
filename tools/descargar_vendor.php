<?php
/**
 * Descarga UNA SOLA VEZ las librerías de front-end a public/vendor/  (ERR-08).
 * Después la aplicación funciona 100 % sin Internet. Sube public/vendor/ a git.
 *
 *   php tools/descargar_vendor.php            descarga lo que falte
 *   php tools/descargar_vendor.php --forzar   vuelve a descargar todo
 *
 * En XAMPP/Windows:  C:\xampp\php\php.exe tools\descargar_vendor.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$BS = '5.3.3';     // Bootstrap
$BI = '1.11.3';    // Bootstrap Icons
$SW = '11.10.8';   // SweetAlert2 v11 (versión fija)

$destino = dirname(__DIR__) . '/public/vendor';
$forzar  = in_array('--forzar', $argv ?? [], true);

// [url, ruta relativa en public/vendor, tamaño mínimo en bytes, marca que debe contener el inicio/contenido]
$archivos = [
    ["https://cdn.jsdelivr.net/npm/bootstrap@$BS/dist/css/bootstrap.min.css",              'bootstrap/css/bootstrap.min.css',                  100000, $BS],
    ["https://cdn.jsdelivr.net/npm/bootstrap@$BS/dist/js/bootstrap.bundle.min.js",         'bootstrap/js/bootstrap.bundle.min.js',              60000, $BS],
    ["https://cdn.jsdelivr.net/npm/bootstrap-icons@$BI/font/bootstrap-icons.min.css",      'bootstrap-icons/font/bootstrap-icons.min.css',      50000, 'bootstrap-icons'],
    ["https://cdn.jsdelivr.net/npm/bootstrap-icons@$BI/font/fonts/bootstrap-icons.woff2",  'bootstrap-icons/font/fonts/bootstrap-icons.woff2', 80000, 'wOF2'],
    ["https://cdn.jsdelivr.net/npm/bootstrap-icons@$BI/font/fonts/bootstrap-icons.woff",   'bootstrap-icons/font/fonts/bootstrap-icons.woff',  100000, 'wOFF'],
    ["https://cdn.jsdelivr.net/npm/sweetalert2@$SW/dist/sweetalert2.all.min.js",           'sweetalert2/sweetalert2.all.min.js',                40000, 'sweetalert2'],
];

function descargar(string $url): ?string
{
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'onix-vendor/1.0', 'follow_location' => 1]]);
    if (in_array('https', stream_get_wrappers(), true) && ini_get('allow_url_fopen')) {
        $d = @file_get_contents($url, false, $ctx);
        if ($d !== false) return $d;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
        $d = curl_exec($ch);
        $ok = $d !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
        if ($ok) return $d;
    }
    return null;
}

echo "Destino: $destino\n\n";
$fallos = 0;
foreach ($archivos as [$url, $rel, $min, $marca]) {
    $ruta = "$destino/$rel";
    if (!$forzar && is_file($ruta) && filesize($ruta) >= $min) { echo "  = ya existe   $rel\n"; continue; }

    $datos = descargar($url);
    if ($datos === null)                          { echo "  ✘ sin descarga $rel  (¿hay Internet? ¿extension=openssl activa en php.ini?)\n"; $fallos++; continue; }
    if (strlen($datos) < $min)                    { echo "  ✘ tamaño raro  $rel (" . strlen($datos) . " bytes)\n"; $fallos++; continue; }
    if (strpos($datos, $marca) === false)         { echo "  ✘ contenido inesperado en $rel\n"; $fallos++; continue; }

    if (!is_dir(dirname($ruta)) && !mkdir(dirname($ruta), 0775, true)) { echo "  ✘ no se pudo crear la carpeta de $rel\n"; $fallos++; continue; }
    file_put_contents($ruta, $datos);
    printf("  ✔ descargado  %s (%d KB)\n", $rel, intdiv(strlen($datos), 1024));
}

echo $fallos ? "\n✘ Faltan $fallos archivo(s). Corrige la conexión y vuelve a ejecutar.\n"
             : "\n✔ public/vendor completo. Ya puedes apagar el Wi-Fi: la app no usa ningún CDN.\n";
exit($fallos ? 1 : 0);
