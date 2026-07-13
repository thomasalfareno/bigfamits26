<?php
// config/smart_migration.php — Intelligent SQL migration engine

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/migration_security.php';

class SmartMigration
{
    private PDO $conn;
    private array $protectedSuperAdminUsernames;
    private array $log = [];
    private array $stats = [
        'tables_synced' => 0,
        'columns_added' => 0,
        'rows_imported' => 0,
        'rows_updated' => 0,
        'rows_skipped' => 0,
        'rows_blocked' => 0,
        'statements_ok' => 0,
        'statements_failed' => 0,
    ];

    private const KNOWN_TABLES = [
        'users', 'notes', 'polls', 'msg', 'msg_deleted',
        'folders', 'files', 'poll_options', 'poll_votes', 'settings',
    ];

    private const BLOCKED_PATTERNS = [
        '/^\s*DROP\s+DATABASE/i',
        '/^\s*CREATE\s+DATABASE/i',
        '/^\s*USE\s+/i',
        '/^\s*GRANT\s+/i',
        '/^\s*REVOKE\s+/i',
        '/^\s*FLUSH\s+PRIVILEGES/i',
        '/^\s*SET\s+GLOBAL\s+/i',
        '/^\s*DROP\s+TABLE/i',
        '/^\s*TRUNCATE\s+/i',
        '/^\s*DELETE\s+/i',
        '/^\s*ALTER\s+/i',
        '/^\s*UPDATE\s+/i',
        '/^\s*ALTER\s+USER/i',
        '/^\s*CREATE\s+USER/i',
        '/^\s*LOAD\s+DATA/i',
        '/^\s*PREPARE\s+/i',
        '/^\s*EXECUTE\s+/i',
    ];

    public function __construct(PDO $conn, array $protectedSuperAdminUsernames = [])
    {
        $this->conn = $conn;
        $this->protectedSuperAdminUsernames = array_values(array_unique(array_map(
            static fn($username) => strtolower(trim((string)$username)),
            array_filter($protectedSuperAdminUsernames, static fn($username) => trim((string)$username) !== '')
        )));
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function run(string $sqlContent): array
    {
        $this->log = [];
        $this->stats = array_fill_keys(array_keys($this->stats), 0);

        $validation = SqlMigrationValidator::validateSqlContent($sqlContent);
        if (!empty($validation['errors'])) {
            return [
                'success' => false,
                'stats' => $this->stats,
                'log' => array_map(fn($e) => ['type' => 'error', 'message' => $e], $validation['errors']),
                'detected_tables' => [],
                'errors' => $validation['errors'],
            ];
        }
        foreach ($validation['warnings'] as $warn) {
            $this->addLog('warn', $warn);
        }

        $sqlContent = $this->normalizeSql($sqlContent);
        $statements = $this->parseSqlStatements($sqlContent);
        $detectedTables = $this->detectTablesInStatements($statements);

        $this->addLog('info', 'File SQL dianalisis: ' . count($statements) . ' perintah, ' . count($detectedTables) . ' tabel terdeteksi.');
        if (!empty($detectedTables)) {
            $this->addLog('info', 'Tabel: ' . implode(', ', $detectedTables));
        }

        $this->syncSchemaFromReference();
        $this->upgradeMissingColumns();

        foreach ($statements as $stmt) {
            $this->processStatement($stmt);
        }

        $this->postProcessUsers();

        return [
            'success' => true,
            'stats' => $this->stats,
            'log' => $this->log,
            'detected_tables' => $detectedTables,
        ];
    }

    private function normalizeSql(string $sql): string
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
        return $sql;
    }

    private function parseSqlStatements(string $sql): array
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
                if ($ch === "\n") {
                    $inLineComment = false;
                }
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

            if (!$inString && $ch === '#' && ($i === 0 || $sql[$i - 1] === "\n" || ctype_space($sql[$i - 1]))) {
                $inLineComment = true;
                continue;
            }

            if (!$inString && $ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }

            if ($inString) {
                $buffer .= $ch;
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
                    $stringChar = '';
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
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    private function detectTablesInStatements(array $statements): array
    {
        $tables = [];
        foreach ($statements as $stmt) {
            if (preg_match('/^\s*(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+[`"]?(\w+)[`"]?/i', $stmt, $m)) {
                $tables[$m[1]] = true;
            }
        }
        return array_values(array_intersect(array_keys($tables), self::KNOWN_TABLES));
    }

    private function isBlocked(string $stmt): bool
    {
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $stmt)) {
                return true;
            }
        }
        return false;
    }

    private function syncSchemaFromReference(): void
    {
        $schemaFile = __DIR__ . '/../database.sql';
        if (!file_exists($schemaFile)) {
            $this->addLog('warn', 'database.sql referensi tidak ditemukan — lewati sinkronisasi skema.');
            return;
        }

        $schemaSql = file_get_contents($schemaFile);
        $schemaStatements = $this->parseSqlStatements($schemaSql);

        foreach ($schemaStatements as $stmt) {
            if (!preg_match('/^\s*CREATE\s+TABLE/i', $stmt)) {
                continue;
            }

            $safeStmt = preg_replace(
                '/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i',
                'CREATE TABLE IF NOT EXISTS ',
                $stmt,
                1
            );

            try {
                $this->conn->exec($safeStmt);
                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i', $stmt, $m)) {
                    $this->stats['tables_synced']++;
                    $this->addLog('ok', "Skema tabel `{$m[1]}` disinkronkan.");
                }
            } catch (PDOException $e) {
                $this->addLog('warn', 'Sinkronisasi skema: ' . $this->shortError($e));
            }
        }
    }

    private function upgradeMissingColumns(): void
    {
        $expected = $this->parseExpectedColumns();
        foreach ($expected as $table => $columns) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $existing = $this->getTableColumns($table);
            foreach ($columns as $colName => $colDef) {
                if (!isset($existing[$colName])) {
                    try {
                        $this->conn->exec("ALTER TABLE `$table` ADD COLUMN `$colName` $colDef");
                        $this->stats['columns_added']++;
                        $this->addLog('ok', "Kolom `$colName` ditambahkan ke `$table`.");
                    } catch (PDOException $e) {
                        $this->addLog('warn', "Gagal menambah kolom `$colName` di `$table`: " . $this->shortError($e));
                    }
                }
            }
        }
    }

    private function parseExpectedColumns(): array
    {
        $schemaFile = __DIR__ . '/../database.sql';
        if (!file_exists($schemaFile)) {
            return [];
        }

        $content = file_get_contents($schemaFile);
        $expected = [];

        if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\((.*?)\)\s*ENGINE/is', $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $table = $match[1];
            $body = $match[2];
            $lines = preg_split('/,\s*\n/', $body);
            $cols = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|CONSTRAINT|FOREIGN\s+KEY)/i', $line)) {
                    continue;
                }
                if (preg_match('/^[`"]?(\w+)[`"]?\s+(.+)$/i', $line, $cm)) {
                    $colDef = rtrim($cm[2], ',');
                    $cols[$cm[1]] = $colDef;
                }
            }

            $expected[$table] = $cols;
        }

        return $expected;
    }

    private function processStatement(string $stmt): void
    {
        if ($this->isBlocked($stmt)) {
            return;
        }

        if (preg_match('/^\s*CREATE\s+TABLE/i', $stmt)) {
            $safeStmt = preg_replace(
                '/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i',
                'CREATE TABLE IF NOT EXISTS ',
                $stmt,
                1
            );
            try {
                $this->conn->exec($safeStmt);
                $this->stats['statements_ok']++;
            } catch (PDOException $e) {
                $this->stats['statements_failed']++;
                $this->addLog('warn', 'CREATE TABLE dilewati: ' . $this->shortError($e));
            }
            return;
        }

        if (preg_match('/^\s*(INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\s+[`"]?(\w+)[`"]?\s*/i', $stmt, $m)) {
            $table = $m[2];
            if (!in_array($table, self::KNOWN_TABLES, true)) {
                return;
            }
            $this->importInsertStatement($stmt, $table, stripos($m[1], 'REPLACE') === 0);
            return;
        }

        if (preg_match('/^\s*(START\s+TRANSACTION|COMMIT|ROLLBACK|SET\s+(?!GLOBAL)|LOCK\s+TABLES|UNLOCK\s+TABLES)/i', $stmt)) {
            try {
                $this->conn->exec($stmt);
            } catch (PDOException $e) {
                // Non-critical session directives
            }
        }
    }

    private function importInsertStatement(string $stmt, string $table, bool $isReplace): void
    {
        $normalized = $this->normalizeInsertStatement($stmt, $table);
        if ($normalized === null) {
            $this->stats['statements_failed']++;
            $this->addLog('warn', "INSERT ke `$table` tidak dapat dinormalisasi.");
            return;
        }

        try {
            $affected = $this->conn->exec($normalized);
            $this->stats['rows_imported'] += max(1, (int)$affected);
            $this->stats['statements_ok']++;
            return;
        } catch (PDOException $e) {
            if ($this->isDuplicateError($e)) {
                $upsert = $this->convertToUpsert($normalized, $table);
                if ($upsert !== null) {
                    try {
                        $this->conn->exec($upsert);
                        $this->stats['rows_updated']++;
                        $this->stats['statements_ok']++;
                        return;
                    } catch (PDOException $e2) {
                        $this->stats['rows_skipped']++;
                        $this->stats['statements_failed']++;
                        $this->addLog('warn', "Baris duplikat di `$table` dilewati.");
                        return;
                    }
                }
            }

            $ignoreStmt = preg_replace('/^\s*INSERT\s+INTO/i', 'INSERT IGNORE INTO', $normalized, 1);
            try {
                $this->conn->exec($ignoreStmt);
                $this->stats['rows_imported']++;
                $this->stats['statements_ok']++;
            } catch (PDOException $e3) {
                $this->stats['rows_skipped']++;
                $this->stats['statements_failed']++;
                $this->addLog('warn', "Import `$table` gagal: " . $this->shortError($e3));
            }
        }
    }

    private function normalizeInsertStatement(string $stmt, string $table): ?string
    {
        if (!preg_match('/^\s*(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\s+[`"]?' . preg_quote($table, '/') . '[`"]?\s*(?:\(([^)]+)\))?\s*(VALUES|SELECT)\s*(.+)$/is', $stmt, $m)) {
            return null;
        }

        $sourceCols = [];
        if (!empty(trim($m[1] ?? ''))) {
            $sourceCols = array_map(function ($c) {
                return trim($c, " `\"'\t\n\r");
            }, explode(',', $m[1]));
        }

        $targetCols = array_keys($this->getTableColumns($table));
        if (empty($targetCols)) {
            return null;
        }

        if (empty($sourceCols)) {
            return preg_replace('/^\s*(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)/i', 'INSERT INTO', $stmt, 1);
        }

        $mappedCols = array_values(array_intersect($sourceCols, $targetCols));
        if (empty($mappedCols)) {
            return null;
        }

        $valueSets = $this->extractInsertValueSets($m[3]);
        if (empty($valueSets)) {
            return null;
        }

        $newValues = [];
        foreach ($valueSets as $valueSet) {
            $values = $this->splitSqlValues($valueSet);
            if (count($values) !== count($sourceCols)) {
                continue;
            }

            if ($table === 'users') {
                $sanitized = sanitizeMigrationUserRow($sourceCols, $values, $this->protectedSuperAdminUsernames);
                if ($sanitized === null) {
                    $this->stats['rows_blocked']++;
                    $this->addLog('warn', 'Baris user diblokir (akun istimewa atau username dilindungi).');
                    continue;
                }
                $values = applyMigrationUserCredentialNormalization($sourceCols, $sanitized);
            }

            $mappedValues = [];
            foreach ($mappedCols as $col) {
                $idx = array_search($col, $sourceCols, true);
                $mappedValues[] = $values[$idx];
            }
            $newValues[] = '(' . implode(', ', $mappedValues) . ')';
        }

        if (empty($newValues)) {
            return null;
        }

        $colList = '`' . implode('`, `', $mappedCols) . '`';
        return 'INSERT INTO `' . $table . '` (' . $colList . ') VALUES ' . implode(', ', $newValues);
    }

    private function extractInsertValueSets(string $valuesPart): array
    {
        $valuesPart = trim($valuesPart);
        if (stripos($valuesPart, 'SELECT') === 0) {
            return [];
        }

        $sets = [];
        $buffer = '';
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $len = strlen($valuesPart);

        for ($i = 0; $i < $len; $i++) {
            $ch = $valuesPart[$i];
            $next = ($i + 1 < $len) ? $valuesPart[$i + 1] : '';

            if ($inString) {
                $buffer .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buffer .= $valuesPart[++$i];
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
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringChar = $ch;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '(') {
                $depth++;
                if ($depth === 1) {
                    $buffer = '';
                    continue;
                }
            }

            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $sets[] = trim($buffer);
                    $buffer = '';
                    continue;
                }
            }

            if ($depth > 0) {
                $buffer .= $ch;
            }
        }

        return $sets;
    }

    private function splitSqlValues(string $valueSet): array
    {
        $values = [];
        $buffer = '';
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $len = strlen($valueSet);

        for ($i = 0; $i < $len; $i++) {
            $ch = $valueSet[$i];
            $next = ($i + 1 < $len) ? $valueSet[$i + 1] : '';

            if ($inString) {
                $buffer .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buffer .= $valueSet[++$i];
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
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringChar = $ch;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '(') {
                $depth++;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                $buffer .= $ch;
                continue;
            }

            if ($ch === ',' && $depth === 0) {
                $values[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        if (trim($buffer) !== '') {
            $values[] = trim($buffer);
        }

        return $values;
    }

    private function convertToUpsert(string $insertStmt, string $table): ?string
    {
        if (!preg_match('/INSERT\s+INTO\s+[`"]?' . preg_quote($table, '/') . '[`"]?\s*\(([^)]+)\)\s*VALUES\s*(.+)$/is', $insertStmt, $m)) {
            return null;
        }

        $skipOnDuplicate = ['id_user', 'id_note', 'id_msg', 'id_poll', 'id_folder', 'id_file', 'id_option', 'id_vote', 'id'];
        if ($table === 'users') {
            $skipOnDuplicate = array_merge($skipOnDuplicate, ['role']);
        }

        $cols = array_map('trim', explode(',', $m[1]));
        $updates = [];
        foreach ($cols as $col) {
            $col = trim($col, '` ');
            if (in_array($col, $skipOnDuplicate, true)) {
                continue;
            }
            $updates[] = "`$col` = VALUES(`$col`)";
        }

        if (empty($updates)) {
            return null;
        }

        return $insertStmt . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }

    private function postProcessUsers(): void
    {
        if (!$this->tableExists('users')) {
            return;
        }

        $stmt = $this->conn->query("SELECT id_user, username, password, plain_password, role FROM users");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $normalized = 0;

        foreach ($users as $user) {
            $role = $user['role'] ?? 'user';
            $allowedRoles = ['user', 'operator', 'admin', 'superadmin'];
            if (!in_array($role, $allowedRoles, true)) {
                $role = $this->mapLegacyRole($role);
            }

            $result = normalizeMigratedUserCredentials(
                $user['password'] ?? '',
                $user['plain_password'] ?? null,
                $role
            );

            $updates = [];
            $params = [];

            if ($result['password'] !== ($user['password'] ?? '')) {
                $updates[] = 'password = ?';
                $params[] = $result['password'];
            }

            $currentPlain = $user['plain_password'] ?? null;
            $newPlain = $result['plain_password'];
            if ($newPlain !== $currentPlain) {
                if ($newPlain === null) {
                    $updates[] = 'plain_password = NULL';
                } else {
                    $updates[] = 'plain_password = ?';
                    $params[] = $newPlain;
                }
            }

            if ($role !== ($user['role'] ?? '')) {
                $updates[] = 'role = ?';
                $params[] = $role;
            }

            if (!empty($updates)) {
                $params[] = $user['id_user'];
                $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id_user = ?';
                $this->conn->prepare($sql)->execute($params);
                if ($result['changed']) {
                    $normalized++;
                }
            }

        }

        if ($normalized > 0) {
            $this->addLog('ok', "$normalized akun dinormalisasi ke password hash satu arah.");
        }
        $this->conn->exec("UPDATE users SET plain_password = NULL");

        // Existing privileged accounts are never demoted or deleted as a side
        // effect of importing a backup. Imported privileged roles were already
        // normalized by sanitizeMigrationUserRow().
    }

    private function mapLegacyRole(string $role): string
    {
        $role = strtolower(trim($role));
        $map = [
            'member' => 'user',
            'peserta' => 'user',
            'moderator' => 'operator',
            'super_admin' => 'superadmin',
            'super-admin' => 'superadmin',
        ];
        return $map[$role] ?? 'user';
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->conn->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function getTableColumns(string $table): array
    {
        $cols = [];
        $stmt = $this->conn->query("SHOW COLUMNS FROM `$table`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[$row['Field']] = $row;
        }
        return $cols;
    }

    private function isDuplicateError(PDOException $e): bool
    {
        $code = (string)$e->getCode();
        $msg = $e->getMessage();
        return $code === '23000' || stripos($msg, 'Duplicate entry') !== false;
    }

    private function shortError(PDOException $e): string
    {
        $msg = $e->getMessage();
        if (strlen($msg) > 120) {
            $msg = substr($msg, 0, 117) . '...';
        }
        return $msg;
    }

    private function addLog(string $type, string $message): void
    {
        $this->log[] = ['type' => $type, 'message' => $message];
    }
}
