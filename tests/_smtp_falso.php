<?php
/**
 * Servidor SMTP de mentira para tests/UsuariosTest.php (solo CLI, un cliente, sin cifrado).
 * Uso: php _smtp_falso.php ARCHIVO_SALIDA   -> imprime el puerto en la primera línea y atiende UNA conexión.
 * Guarda en ARCHIVO_SALIDA un JSON con: user, pass, from, to, data.
 */
if (PHP_SAPI !== 'cli') exit;
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$srv) { fwrite(STDERR, "no se pudo abrir: $errstr\n"); exit(1); }
$puerto = (int)substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
echo $puerto, "\n";
fflush(STDOUT);

$con = @stream_socket_accept($srv, 20);
if (!$con) exit(1);
$r = ['user' => '', 'pass' => '', 'from' => '', 'to' => '', 'data' => ''];
$say = fn(string $s) => fwrite($con, $s . "\r\n");
$say('220 falso.local ESMTP');
$modo = '';
while (($l = fgets($con)) !== false) {
    $l = rtrim($l, "\r\n");
    if ($modo === 'user') { $r['user'] = base64_decode($l); $modo = 'pass'; $say('334 UGFzc3dvcmQ6'); continue; }
    if ($modo === 'pass') { $r['pass'] = base64_decode($l); $modo = ''; $say('235 2.7.0 ok'); continue; }
    if ($modo === 'data') {
        if ($l === '.') { $modo = ''; $say('250 2.0.0 queued'); continue; }
        $r['data'] .= $l . "\r\n";
        continue;
    }
    $u = strtoupper($l);
    if (str_starts_with($u, 'EHLO')) { fwrite($con, "250-falso.local\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n"); }
    elseif ($u === 'AUTH LOGIN') { $modo = 'user'; $say('334 VXNlcm5hbWU6'); }
    elseif (str_starts_with($u, 'MAIL FROM:')) { $r['from'] = $l; $say('250 ok'); }
    elseif (str_starts_with($u, 'RCPT TO:')) { $r['to'] = $l; $say('250 ok'); }
    elseif ($u === 'DATA') { $modo = 'data'; $say('354 adelante'); }
    elseif ($u === 'QUIT') { $say('221 adios'); break; }
    else { $say('500 no entiendo'); }
}
file_put_contents($argv[1], json_encode($r));
