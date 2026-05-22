<?php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/healthz") {
    header('Content-Type: application/json');
    echo '{"status":"ok"}';
    return true;
}
if (strpos($path, "/api/v1/") === 0) {
    require_once __DIR__."/gateway.php";
    gateway_handle_request($path);
    return true;
}

if (strpos($path, "/uploads/") === 0 || strpos($path, "/vulnbank/online/uploads/") === 0) {
    require_once __DIR__."/gateway.php";
    gateway_proxy_file_request($path);
    return true;
}

if ($path === "/vulnbank/online/api.php") {
    require __DIR__."/vulnbank/online/api.php";
    return true;
}

$file = realpath(__DIR__.$path);
if ($path !== "/" && $file && strpos($file, realpath(__DIR__)) === 0 && is_file($file)) {
    return false;
}

if ($path === "/" || $path === "") {
    require __DIR__."/index.php";
    return true;
}

return false;
