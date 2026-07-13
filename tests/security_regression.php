<?php

require_once __DIR__ . '/../config/migration_security.php';

$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(!isStrongPassword('1234567'), 'Password tujuh karakter harus ditolak.');
$assert(isStrongPassword('abcdefgh'), 'Password delapan huruf harus diterima.');
$assert(isStrongPassword('12345678'), 'Password delapan angka harus diterima.');

$columns = ['username', 'role', 'password'];
$protected = ['currentadmin'];
$assert(
    sanitizeMigrationUserRow($columns, ["'currentadmin'", "'admin'", "'hash'"], $protected) === null,
    'Migrasi tidak boleh menimpa akun istimewa yang dilindungi.'
);

$normalized = sanitizeMigrationUserRow(
    $columns,
    ["'importeduser'", "'superadmin'", "'legacy-password'"],
    $protected
);
$assert($normalized !== null, 'User biasa dari backup harus tetap dapat diimpor.');
$assert(($normalized[1] ?? null) === "'operator'", 'Role superadmin dari backup harus diturunkan.');

$databaseConfig = __DIR__ . '/../config/database.php';
if (is_file($databaseConfig)) {
    require $databaseConfig;
    $superAdminId = (int)$conn
        ->query("SELECT id_user FROM users WHERE role = 'superadmin' ORDER BY id_user LIMIT 1")
        ->fetchColumn();
    $assert($superAdminId > 0, 'Database pengujian harus memiliki akun superadmin.');
    if ($superAdminId > 0) {
        $assert(
            !SqlMigrationValidator::verifySuperAdminPassword($conn, $superAdminId, 'definitely-wrong-password'),
            'Password migrasi yang salah harus ditolak.'
        );
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Security regression tests: OK" . PHP_EOL;
