<?php
/** Ejecuta toda la suite. Uso:  php tests/run.php   (BD de pruebas: joyeria_onix_test en el puerto 3307) */
$fallos = 0;
foreach (['KardexTest', 'OperacionesTest', 'ConcurrenciaTest', 'ReportesTest', 'UsuariosTest', 'RespaldosTest'] as $t) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/$t.php"), $rc);
    $fallos += $rc ? 1 : 0;
}
echo $fallos ? "\n✘ HAY SUITES CON FALLOS\n" : "\n✔ TODA LA SUITE PASÓ\n";
exit($fallos ? 1 : 0);
