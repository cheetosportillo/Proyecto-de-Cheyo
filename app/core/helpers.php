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
