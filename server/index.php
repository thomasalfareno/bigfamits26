<?php
// server/index.php
$base_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base_url = preg_replace('/(\/(auth|dashboard|notes|msg|drive|calendar|server|admin|installer))?$/i', '', $base_url);
if ($base_url === '/') $base_url = '';

header("Location: " . $base_url . "/msg/");
exit;
