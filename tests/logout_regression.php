<?php

$root = dirname(__DIR__);
$header = file_get_contents($root . '/template/header.php');
$footer = file_get_contents($root . '/template/footer.php');
$logout = file_get_contents($root . '/auth/logout.php');

$failures = [];
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};

$assertContains('id="logoutDialog"', $header, 'Dialog logout native wajib tersedia.');
$assertContains('method="POST"', $header, 'Logout wajib dikirim melalui POST.');
$assertContains('name="_csrf_token"', $header, 'Form logout wajib membawa token CSRF.');
$assertContains("logoutDialog.showModal()", $footer, 'Tombol logout wajib membuka dialog native.');
$assertContains("logoutCancelButton?.addEventListener('click'", $footer, 'Tombol batal wajib memiliki handler.');
$assertNotContains("Swal.fire({\n                title: 'Keluar?'", $footer, 'Logout tidak boleh bergantung pada SweetAlert.');
$assertContains("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $logout, 'Endpoint logout wajib menolak metode selain POST.');
$assertContains('validateCsrfToken()', $logout, 'Endpoint logout wajib memvalidasi CSRF.');
$assertContains("'/auth/login', true, 303", $logout, 'Logout sukses wajib memakai redirect 303.');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Logout regression test: OK" . PHP_EOL;
