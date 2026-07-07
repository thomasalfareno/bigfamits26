<?php
// config/migration_security.php — Security layer for SQL migration

require_once __DIR__ . '/security.php';

class SqlMigrationValidator
{
    /** Dangerous patterns anywhere in file content */
    private const CONTENT_BLOCK_PATTERNS = [
        '/<\?php/i',
        '/<\?=/i',
        '/<\?/i',
        '/\bLOAD_FILE\s*\(/i',
        '/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',
        '/\b(?:SLEEP|BENCHMARK)\s*\(/i',
        '/\bEXEC(?:UTE)?\s*\(/i',
        '/\bxp_cmdshell\b/i',
        '/\binformation_schema\b/i',
        '/\bmysql\.(?:user|db)\b/i',
        '/`mysql`/i',
        '/\bperformance_schema\b/i',
        '/@@(?:global|session|local)\./i',
        '/\bUNION\s+(?:ALL\s+)?SELECT\b/i',
        '/0x[0-9a-f]{20,}/i',
        '/\\x[0-9a-f]{2}/i',
        '/\bCHAR\s*\(\s*\d+/i',
        '/\bCONCAT\s*\(\s*0x/i',
    ];

    /** Blocked statement types (checked per statement) */
    private const STATEMENT_BLOCK_PATTERNS = [
        '/^\s*DROP\s+/i',
        '/^\s*TRUNCATE\s+/i',
        '/^\s*DELETE\s+/i',
        '/^\s*RENAME\s+/i',
        '/^\s*CREATE\s+(?:DATABASE|USER|EVENT|PROCEDURE|FUNCTION|TRIGGER|VIEW|INDEX)\b/i',
        '/^\s*DROP\s+(?:DATABASE|USER|EVENT|PROCEDURE|FUNCTION|TRIGGER|VIEW|INDEX)\b/i',
        '/^\s*GRANT\s+/i',
        '/^\s*REVOKE\s+/i',
        '/^\s*USE\s+/i',
        '/^\s*SET\s+GLOBAL\s+/i',
        '/^\s*FLUSH\s+/i',
        '/^\s*KILL\s+/i',
        '/^\s*SHUTDOWN\b/i',
        '/^\s*PREPARE\s+/i',
        '/^\s*EXECUTE\s+/i',
        '/^\s*DEALLOCATE\s+/i',
        '/^\s*CALL\s+/i',
        '/^\s*LOAD\s+DATA/i',
        '/^\s*HANDLER\s+/i',
        '/^\s*LOCK\s+TABLES/i',
        '/^\s*UNLOCK\s+TABLES/i',
    ];

    private const ALLOWED_TABLES = [
        'users', 'notes', 'polls', 'msg', 'msg_deleted',
        'folders', 'files', 'poll_options', 'poll_votes', 'settings',
    ];

    private const MAX_FILE_SIZE = 50 * 1024 * 1024;
    private const MAX_STATEMENTS = 5000;
    private const MAX_INSERT_ROWS = 10000;

    public static function validateUploadedFile(array $file): array
    {
        $errors = [];

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'errors' => ['File upload tidak valid.'], 'warnings' => []];
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'errors' => ['Gagal mengupload file.'], 'warnings' => []];
        }

        if (($file['size'] ?? 0) > self::MAX_FILE_SIZE) {
            $errors[] = 'Ukuran file melebihi batas 50 MB.';
        }

        $name = $file['name'] ?? '';
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'sql') {
            $errors[] = 'Hanya file .sql yang diperbolehkan.';
        }

        if (preg_match('/[^a-zA-Z0-9._\-]/', basename($name))) {
            $errors[] = 'Nama file mengandung karakter tidak valid.';
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false || trim($content) === '') {
            $errors[] = 'File SQL kosong atau tidak dapat dibaca.';
            return ['valid' => false, 'errors' => $errors, 'warnings' => []];
        }

        if (!mb_check_encoding($content, 'UTF-8') && !mb_check_encoding($content, 'ASCII')) {
            $errors[] = 'File bukan teks SQL valid (encoding tidak didukung).';
        }

        if (strpos($content, "\0") !== false) {
            $errors[] = 'File mengandung data biner — ditolak.';
        }

        $contentResult = self::validateSqlContent($content);
        $errors = array_merge($errors, $contentResult['errors']);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $contentResult['warnings'],
        ];
    }

    public static function validateSqlContent(string $content): array
    {
        $errors = [];
        $warnings = [];

        foreach (self::CONTENT_BLOCK_PATTERNS as $pattern) {
            if (preg_match($pattern, $content)) {
                $errors[] = 'File SQL mengandung pola berbahaya dan ditolak demi keamanan database.';
                break;
            }
        }

        $statements = self::splitStatements($content);

        if (count($statements) > self::MAX_STATEMENTS) {
            $errors[] = 'Terlalu banyak perintah SQL (' . count($statements) . '). Maksimal ' . self::MAX_STATEMENTS . '.';
        }

        $insertRowCount = 0;
        $unknownTables = [];

        foreach ($statements as $stmt) {
            foreach (self::STATEMENT_BLOCK_PATTERNS as $pattern) {
                if (preg_match($pattern, $stmt)) {
                    $errors[] = 'Perintah SQL berbahaya terdeteksi dan diblokir.';
                    break 2;
                }
            }

            if (preg_match('/^\s*UPDATE\s+[`"]?(\w+)[`"]?\s+/i', $stmt, $m)) {
                if (!in_array($m[1], self::ALLOWED_TABLES, true)) {
                    $unknownTables[$m[1]] = true;
                }
                if ($m[1] === 'users' && preg_match('/\brole\s*=\s*[\'"]?superadmin/i', $stmt)) {
                    $errors[] = 'Manipulasi role superadmin via UPDATE terdeteksi — ditolak.';
                }
            }

            if (preg_match('/^\s*(?:INSERT|REPLACE)\s+/i', $stmt)) {
                if (preg_match('/INTO\s+[`"]?(\w+)[`"]?/i', $stmt, $tm)) {
                    if (!in_array($tm[1], self::ALLOWED_TABLES, true)) {
                        $unknownTables[$tm[1]] = true;
                    }
                }
                $insertRowCount += max(1, substr_count($stmt, '),(') + 1);
            }
        }

        if ($insertRowCount > self::MAX_INSERT_ROWS) {
            $errors[] = "Terlalu banyak baris data ($insertRowCount). Maksimal " . self::MAX_INSERT_ROWS . '.';
        }

        if (!empty($unknownTables)) {
            $warnings[] = 'Tabel tidak dikenali akan diabaikan: ' . implode(', ', array_keys($unknownTables));
        }

        $hasData = false;
        foreach ($statements as $stmt) {
            if (preg_match('/^\s*(?:INSERT|REPLACE|UPDATE)\s+/i', $stmt)) {
                $hasData = true;
                break;
            }
        }
        if (!$hasData) {
            $warnings[] = 'Tidak ada data INSERT/UPDATE terdeteksi — hanya skema yang akan disinkronkan.';
        }

        return ['errors' => array_unique($errors), 'warnings' => $warnings];
    }

    /**
     * Verify superadmin password for migration on live system.
     */
    public static function verifySuperAdminPassword(string $password): bool
    {
        $password = trim($password);
        if ($password === '') {
            return false;
        }

        $config = getSuperAdminConfig();
        if (empty($config['password_hash'])) {
            return false;
        }

        return password_verify($password, $config['password_hash']);
    }

    /**
     * Require logged-in superadmin when database is already configured.
     */
    public static function requireSuperAdminSession(): bool
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin';
    }

    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($ch === "\n") $inLineComment = false;
                continue;
            }
            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }
            if (!$inString && $ch === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if (!$inString && $ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }

            if ($inString) {
                $buffer .= $ch;
                if ($ch === $stringChar && !($stringChar === "'" && $next === "'")) {
                    if ($ch === '\\' && $i + 1 < $len) {
                        $buffer .= $sql[++$i];
                        continue;
                    }
                    if ($ch === $stringChar) {
                        if ($stringChar === "'" && $next === "'") {
                            $buffer .= $next;
                            $i++;
                            continue;
                        }
                        $inString = false;
                    }
                } elseif ($ch === $stringChar && $stringChar === "'" && $next === "'") {
                    $buffer .= $next;
                    $i++;
                } elseif ($ch === '\\' && $i + 1 < $len) {
                    $buffer .= $sql[++$i];
                } elseif ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringChar = $ch;
                $buffer .= $ch;
                continue;
            }

            if ($ch === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '') $statements[] = $trimmed;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') $statements[] = $trimmed;

        return $statements;
    }
}

/**
 * Sanitize user row values during import — prevent privilege escalation.
 */
function sanitizeMigrationUserRow(array $columns, array $values): ?array
{
    $data = array_combine($columns, $values);
    if ($data === false) {
        return null;
    }

    $username = trim($data['username'] ?? '', " '\"");
    $role = trim($data['role'] ?? 'user', " '\"");

    if ($username === '') {
        return null;
    }

    if (isReservedSuperAdminUsername($username)) {
        return null;
    }

    if (strtolower($role) === 'superadmin') {
        $data['role'] = "'operator'";
    }

    if (strtolower($role) === 'admin') {
        $data['role'] = "'operator'";
    }

    $allowedRoles = ['user', 'operator'];
    $cleanRole = trim($data['role'] ?? '', " '\"");
    if (!in_array(strtolower($cleanRole), $allowedRoles, true)) {
        $data['role'] = "'user'";
    }

    $rebuilt = [];
    foreach ($columns as $col) {
        $rebuilt[] = $data[$col] ?? 'NULL';
    }

    return $rebuilt;
}

/**
 * Parse a SQL literal from an INSERT value list.
 */
function parseMigrationSqlValue(string $sqlValue): ?string
{
    $v = trim($sqlValue);
    if ($v === '' || strtoupper($v) === 'NULL') {
        return null;
    }

    if ((($v[0] ?? '') === "'" && substr($v, -1) === "'") || (($v[0] ?? '') === '"' && substr($v, -1) === '"')) {
        $inner = substr($v, 1, -1);
        return str_replace(["''", "\\'", '\\"', '\\\\'], ["'", "'", '"', '\\'], $inner);
    }

    return $v;
}

/**
 * Quote a string for SQL INSERT/UPDATE literals.
 */
function quoteMigrationSqlValue(?string $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * Normalize password + plain_password on each imported user row (same rules as admin/register).
 */
function applyMigrationUserCredentialNormalization(array $columns, array $values): array
{
    $data = array_combine($columns, $values);
    if ($data === false) {
        return $values;
    }

    $role = trim(parseMigrationSqlValue($data['role'] ?? 'user') ?? 'user');
    $password = parseMigrationSqlValue($data['password'] ?? '') ?? '';
    $plainPassword = parseMigrationSqlValue($data['plain_password'] ?? null);

    $result = normalizeMigratedUserCredentials($password, $plainPassword, $role);

    if (array_key_exists('password', $data)) {
        $data['password'] = quoteMigrationSqlValue($result['password']);
    }
    if (array_key_exists('plain_password', $data)) {
        $data['plain_password'] = $result['plain_password'] === null
            ? 'NULL'
            : quoteMigrationSqlValue($result['plain_password']);
    }

    $rebuilt = [];
    foreach ($columns as $col) {
        $rebuilt[] = $data[$col] ?? 'NULL';
    }

    return $rebuilt;
}

/**
 * Check if a value is already AES-encrypted via encryptUserData().
 */
function isEncryptedUserData(?string $data): bool
{
    return isPlainPasswordEncrypted($data);
}

/**
 * Check if a string looks like a bcrypt hash.
 */
function looksLikeBcryptHash(string $hash): bool
{
    return (bool)preg_match('/^\$2[ayb]\$\d{2}\$/', $hash);
}

/**
 * Normalize password + plain_password after migration — same rules as admin/register.
 *
 * Priority for plain-text source:
 * 1. plain_password column (if plain text, not yet encrypted)
 * 2. password column (if not bcrypt — legacy plain-text storage)
 * 3. password column (if AES-encrypted legacy format — decrypt temporarily)
 *
 * If only bcrypt exists with no plain source, password stays bcrypt and plain_password stays NULL
 * until the user logs in successfully (backfillPlainPasswordAfterLogin).
 */
function normalizeMigratedUserCredentials(string $password, ?string $plainPassword, string $role): array
{
    $password = trim($password);
    $plainPassword = $plainPassword !== null ? trim($plainPassword) : '';

    if (!shouldStorePlainPassword($role)) {
        if ($password !== '' && !looksLikeBcryptHash($password)) {
            return [
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'plain_password' => null,
                'changed' => true,
            ];
        }
        return [
            'password' => $password,
            'plain_password' => null,
            'changed' => false,
        ];
    }

    $plainSource = null;

    if ($plainPassword !== '' && !isEncryptedUserData($plainPassword)) {
        $plainSource = $plainPassword;
    } elseif ($password !== '' && !looksLikeBcryptHash($password)) {
        $plainSource = $password;
    } elseif ($password !== '' && isEncryptedUserData($password)) {
        $decryptedPassword = decryptUserData($password);
        if ($decryptedPassword !== null && $decryptedPassword !== '' && $decryptedPassword !== $password) {
            $plainSource = $decryptedPassword;
        }
    }

    if ($plainSource === null) {
        if ($plainPassword !== '' && isEncryptedUserData($plainPassword)) {
            return [
                'password' => $password,
                'plain_password' => $plainPassword,
                'changed' => false,
            ];
        }

        if ($plainPassword !== '' && !isEncryptedUserData($plainPassword)) {
            $encryptedPlain = encryptUserData($plainPassword);
            return [
                'password' => $password,
                'plain_password' => $encryptedPlain,
                'changed' => true,
            ];
        }

        return [
            'password' => $password,
            'plain_password' => null,
            'changed' => false,
        ];
    }

    if (looksLikeBcryptHash($password) && password_verify($plainSource, $password)) {
        $hashed = $password;
    } else {
        $hashed = password_hash($plainSource, PASSWORD_DEFAULT);
    }

    $encryptedPlain = encryptUserData($plainSource);
    $changed = ($hashed !== $password) || ($encryptedPlain !== $plainPassword);

    return [
        'password' => $hashed,
        'plain_password' => $encryptedPlain,
        'changed' => $changed,
    ];
}
