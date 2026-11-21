<?php
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return MONEDA . number_format((float)$n, 2); }
function base_url(): string {
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $dir;
}
function url(string $c, string $a = 'index', array $q = []): string {
    return base_url() . '/index.php?' . http_build_query(array_merge(['c' => $c, 'a' => $a], $q));
}
function asset(string $path): string { return base_url() . '/public/' . ltrim($path, '/'); }

/* ---------- Dinero exacto: SIEMPRE enteros en centavos (K4) ---------- */

/** "12.34" | 12.34 | "12" -> 1234 (int). Redondea a centavos. */
function a_centavos($v): int { return (int)round(((float)$v) * 100); }

/** 1234 -> "12.34" (string exacto, sin floats). */
function de_centavos(int $c): string {
    $neg = $c < 0; $c = abs($c);
    return ($neg ? '-' : '') . intdiv($c, 100) . '.' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT);
}

/** Entero escalado -> string con $dec decimales. Ej: de_escala(33333, 4) = "3.3333". */
function de_escala(int $v, int $dec): string {
    $neg = $v < 0; $v = abs($v); $f = 10 ** $dec;
    return ($neg ? '-' : '') . intdiv($v, $f) . '.' . str_pad((string)($v % $f), $dec, '0', STR_PAD_LEFT);
}

/** "3.333" -> 3333 (milésimas, para pesos). */
function a_milesimas($v): int { return (int)round(((float)$v) * 1000); }

/** División entera redondeando a la mitad hacia arriba (solo positivos). */
function div_redondeada(int $num, int $den): int { return intdiv(2 * $num + $den, 2 * $den); }

/** Peso con 3 decimales + unidad (N13). */
function fmt_peso($peso, string $unidad): string {
    return number_format((float)$peso, 3) . ' ' . ($unidad === 'kilates' ? 'kt' : 'g');
}

/** Valida uuid v4 generado por el navegador o el servidor. */
function uuid_valido(?string $u): bool {
    return is_string($u) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $u) === 1;
}

/** Celda CSV a prueba de inyección de fórmulas (=,+,-,@). Los números reales no se tocan. */
function csv_celda($v): string {
    $s = (string)$v;
    if ($s !== '' && !is_numeric($s) && preg_match('/^[=+\-@\t\r]/', $s)) $s = "'" . $s;
    return $s;
}

/** Rango de fechas semiabierto [desde, hasta+1día) para filtros "fecha >= ? AND fecha < ?" (N6). */
function rango_fechas(string $desde, string $hasta): array {
    $ok = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && checkdate((int)substr($f, 5, 2), (int)substr($f, 8, 2), (int)substr($f, 0, 4));
    if (!$ok($desde)) $desde = date('Y-m-d');
    if (!$ok($hasta)) $hasta = $desde;
    if ($hasta < $desde) $hasta = $desde;
    $fin = (new DateTime($hasta))->modify('+1 day')->format('Y-m-d');
    return [$desde, $hasta, $desde . ' 00:00:00', $fin . ' 00:00:00'];
}

/** Largo en caracteres (UTF-8) sin depender de la extensión mbstring. */
function largo_texto(string $s): int { return (int)preg_match_all('/./us', $s); }
