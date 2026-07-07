<?php
// index.php
$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
if ($base_url === '/') $base_url = '';

if (!file_exists(__DIR__ . '/config/database.php')) {
    header("Location: " . $base_url . "/installer/");
    exit;
} else {
    header("Location: " . $base_url . "/dashboard/");
    exit;
}
?>
