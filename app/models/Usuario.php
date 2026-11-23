<?php
class Usuario
{
    public const MIN_PASSWORD = 8;

    public static function porUsuario(string $u): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM usuarios WHERE usuario = ? LIMIT 1');
        $st->execute([$u]);
        return $st->fetch() ?: null;
    }

    public static function porId(int $id): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM usuarios WHERE id_usuario = ? LIMIT 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Nunca devuelve hashes: solo si tiene llave maestra (tiene_llave) y si debe cambiar la clave. */
    public static function listar(): array
    {
        return Database::get()->query(
            'SELECT id_usuario, nombre, usuario, email, rol, estado, debe_cambiar_password, created_at,
                    (codigo_recuperacion_hash IS NOT NULL) AS tiene_llave
               FROM usuarios ORDER BY nombre'
        )->fetchAll();
    }

    public static function existe(string $usuario, int $excepto = 0): bool
    {
        $st = Database::get()->prepare('SELECT 1 FROM usuarios WHERE usuario = ? AND id_usuario <> ?');
        $st->execute([$usuario, $excepto]);
        return (bool)$st->fetchColumn();
    }

    /* ---------- Validaciones y claves ---------- */

    /** Mensaje de error si la contraseña no cumple la política (8-72 bytes), o null si es válida. */
    public static function validarPassword(string $p): ?string
    {
        if (strlen($p) < self::MIN_PASSWORD) return 'La contraseña debe tener al menos ' . self::MIN_PASSWORD . ' caracteres.';
        if (strlen($p) > 72) return 'La contraseña no puede superar 72 caracteres.';
        return null;
    }

    /**
     * Correo opcional. '' -> null. Lanza InvalidArgumentException con mensaje apto para mostrar.
     */
    public static function normalizarEmail(string $e): ?string
    {
        $e = trim($e);
        if ($e === '') return null;
        if (strlen($e) > 150 || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El correo no es válido.');
        }
        return $e;
    }

    /** Clave temporal de 10 caracteres sin símbolos ambiguos (0/O, 1/l/I). */
    public static function claveTemporal(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $n = strlen($alfabeto) - 1;
        $c = '';
        for ($i = 0; $i < 10; $i++) $c .= $alfabeto[random_int(0, $n)];
        return $c;
    }

    /* ---------- Altas, cambios ---------- */

    /**
     * Crea un usuario con clave temporal (debe_cambiar_password = 1). $d['llave_hash'] solo para la Dueña.
     * @return int id del usuario nuevo
     */
    public static function crear(array $d): int
    {
        $st = Database::get()->prepare(
            'INSERT INTO usuarios (nombre, usuario, email, password, rol, estado, debe_cambiar_password, codigo_recuperacion_hash)
             VALUES (?,?,?,?,?,1,1,?)'
        );
        $st->execute([
            $d['nombre'], $d['usuario'], $d['email'] ?? null, password_hash($d['password'], PASSWORD_DEFAULT),
            $d['rol'], $d['llave_hash'] ?? null,
        ]);
        return (int)Database::get()->lastInsertId();
    }

    /**
     * Edita datos (nunca la contraseña). Si pasa a 'cajero' se borra su llave maestra; si pasa a 'duena' y
     * viene $d['llave_hash'], se guarda esa llave nueva.
     */
    public static function actualizar(int $id, array $d): void
    {
        $sql = 'UPDATE usuarios SET nombre = ?, usuario = ?, email = ?, rol = ?';
        $p = [$d['nombre'], $d['usuario'], $d['email'] ?? null, $d['rol']];
        if ($d['rol'] === 'cajero') {
            $sql .= ', codigo_recuperacion_hash = NULL';
        } elseif (!empty($d['llave_hash'])) {
            $sql .= ', codigo_recuperacion_hash = ?';
            $p[] = $d['llave_hash'];
        }
        $p[] = $id;
        Database::get()->prepare($sql . ' WHERE id_usuario = ?')->execute($p);
    }

    public static function cambiarEstado(int $id, int $estado): void
    {
        Database::get()->prepare('UPDATE usuarios SET estado=? WHERE id_usuario=?')->execute([$estado, $id]);
    }

    /**
     * Fija una contraseña nueva y anula cualquier código pendiente del correo.
     * $forzarCambio = true para claves temporales (el usuario deberá cambiarla al entrar).
     */
    public static function establecerPassword(int $id, string $password, bool $forzarCambio): void
    {
        Database::get()->prepare(
            'UPDATE usuarios SET password = ?, debe_cambiar_password = ?, reset_token_hash = NULL, reset_token_expira = NULL
              WHERE id_usuario = ?'
        )->execute([password_hash($password, PASSWORD_DEFAULT), $forzarCambio ? 1 : 0, $id]);
    }

    /** Recuperación con la llave maestra: contraseña nueva + llave rotada en un solo UPDATE. */
    public static function establecerPasswordYLlave(int $id, string $password, string $llaveHash): void
    {
        Database::get()->prepare(
            'UPDATE usuarios SET password = ?, debe_cambiar_password = 0, codigo_recuperacion_hash = ?,
                    reset_token_hash = NULL, reset_token_expira = NULL
              WHERE id_usuario = ?'
        )->execute([password_hash($password, PASSWORD_DEFAULT), $llaveHash, $id]);
    }

    /* ---------- Código temporal enviado por correo (15 minutos) ---------- */

    public const MINUTOS_CODIGO = 15;

    public static function guardarTokenCorreo(int $id, string $hash): void
    {
        Database::get()->prepare(
            'UPDATE usuarios SET reset_token_hash = ?, reset_token_expira = DATE_ADD(NOW(), INTERVAL ' . self::MINUTOS_CODIGO . ' MINUTE)
              WHERE id_usuario = ?'
        )->execute([$hash, $id]);
    }

    /** Hash del código solo si todavía no venció. */
    public static function tokenCorreo(int $id): ?string
    {
        $st = Database::get()->prepare(
            'SELECT reset_token_hash FROM usuarios WHERE id_usuario = ? AND reset_token_hash IS NOT NULL AND reset_token_expira > NOW()'
        );
        $st->execute([$id]);
        $h = $st->fetchColumn();
        return $h === false || $h === null || $h === '' ? null : (string)$h;
    }

    public static function invalidarTokenCorreo(int $id): void
    {
        Database::get()->prepare('UPDATE usuarios SET reset_token_hash = NULL, reset_token_expira = NULL WHERE id_usuario = ?')->execute([$id]);
    }
}
