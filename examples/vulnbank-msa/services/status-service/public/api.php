<?php
$_uri_path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
if ($_uri_path === "/healthz") {
    header('Content-Type: application/json');
    echo '{"status":"ok"}';
    exit;
}
if ($_uri_path === "/openapi.json") {
    header('Content-Type: application/json');
    readfile(__DIR__ . "/openapi.json");
    exit;
}
include("inc/status_bootstrap.php");
include("inc/service_client.php");
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

function statusValidateArgs($args) {
    if (!isset($args["type"])) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "type"), "user", array("variable" => ""));
    if (!isset($args["action"])) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "action"), "user", array("variable" => ""));
}

function statusParts($command, $indexes) {
    $output = shell_exec($command);
    $parts = $output ? preg_split('/\s+/', trim($output)) : array();
    $result = array();
    foreach ($indexes as $name => $index) {
        $result[$name] = isset($parts[$index]) ? $parts[$index] : "0";
    }
    return $result;
}

function statusHttpCode($headers) {
    if (!is_array($headers)) {
        return 0;
    }
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/[0-9.]+\s+([0-9]+)/', $header, $match)) {
            return (int)$match[1];
        }
    }
    return 0;
}

function statusPostProbe($name, $url, $payload) {
    $started = microtime(true);
    $body = http_build_query($payload);
    $headers = "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n";
    $token = vb_token_from_request();
    if ($token) {
        $headers .= "Authorization: Bearer ".$token."\r\n";
    }
    $context = stream_context_create(array(
        "http" => array(
            "method" => "POST",
            "header" => $headers,
            "content" => $body,
            "timeout" => 3,
            "ignore_errors" => true
        )
    ));
    $response = @file_get_contents($url, false, $context);
    $elapsed = microtime(true) - $started;
    $code = isset($http_response_header) ? statusHttpCode($http_response_header) : 0;
    $decoded = is_string($response) ? json_decode($response, true) : NULL;
    return array(
        "service" => $name,
        "url" => $url,
        "code" => $code,
        "time" => $elapsed,
        "ok" => ($code >= 200 && $code < 400),
        "body" => is_array($decoded) ? $decoded : $response
    );
}

function statusGetProbe($name, $url) {
    $started = microtime(true);
    $context = stream_context_create(array(
        "http" => array(
            "method" => "GET",
            "timeout" => 3,
            "ignore_errors" => true,
            "header" => "User-Agent: Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.0)\r\n"
        )
    ));
    $response = @file_get_contents($url, false, $context);
    $elapsed = microtime(true) - $started;
    $code = isset($http_response_header) ? statusHttpCode($http_response_header) : 0;
    return array(
        "service" => $name,
        "url" => $url,
        "code" => $code,
        "time" => $elapsed,
        "ok" => ($code >= 200 && $code < 400),
        "body_size" => $response === false ? 0 : strlen($response)
    );
}

function statusServiceUrls() {
    return array(
        "user-service" => getenv("USER_SERVICE_URL") ?: "http://user-service:8080/api.php",
        "transaction-service" => getenv("TRANSACTION_SERVICE_URL") ?: "http://transaction-service:8080/api.php",
        "settings-service" => getenv("SETTINGS_SERVICE_URL") ?: "http://settings-service:8080/api.php",
        "file-service" => getenv("FILE_SERVICE_URL") ?: "http://file-service:8080/api.php",
        "frontend" => getenv("FRONTEND_SERVICE_URL") ?: "http://vulnbank-msa-frontend:8080/"
    );
}

function statusServiceStateLocal($args) {
    $services = array();
    foreach (statusServiceUrls() as $name => $url) {
        if ($name == "frontend") {
            $services[] = statusGetProbe($name, $url);
        } else {
            $services[] = statusPostProbe($name, $url, array("type" => "status", "action" => "ping"));
        }
    }

    $disk = statusParts("df -h --total 2>/dev/null | grep total", array("total" => 1, "used" => 2, "free" => 3));
    $memory = statusParts("free -m 2>/dev/null | grep Mem", array("total" => 1, "used" => 2, "free" => 3));
    $swap = statusParts("free -m 2>/dev/null | grep Swap", array("total" => 1, "used" => 2, "free" => 3));
    $ok = true;
    foreach ($services as $service) {
        if (!$service["ok"]) {
            $ok = false;
        }
    }
    $payload = array(
        "status" => ($ok ? "success" : "error"),
        "services" => $services,
        "disk" => $disk,
        "memory" => $memory,
        "swap" => $swap,
        "session" => session_id()
    );
    if (!$ok) {
        header(':', true, 400);
    }
    die(json_encode($payload));
}

function statusDashboardLocal() {
    $user_summary = vb_call_user_service("summary", array());
    $transaction_summary = vb_transaction_summary(vb_current_session_id());
    responseSend(TRUE, "Dashboard statistics", "graph",
        array("users" => isset($user_summary["users"]) ? (int)$user_summary["users"] : 0,
              "transactions" => isset($transaction_summary["transactions"]) ? (int)$transaction_summary["transactions"] : 0,
              "pending_transactions" => isset($transaction_summary["pending_transactions"]) ? (int)$transaction_summary["pending_transactions"] : 0,
              "failed_transactions" => isset($transaction_summary["failed_transactions"]) ? (int)$transaction_summary["failed_transactions"] : 0,
              "balances" => isset($user_summary["balances"]) ? $user_summary["balances"] : array(),
              "session" => session_id()));
}

function statusSessionLocal() {
    responseSend(TRUE, "Session active", "key", array(
        "id" => isset($_SESSION["id"]) ? $_SESSION["id"] : NULL,
        "login" => isset($_SESSION["login"]) ? $_SESSION["login"] : NULL,
        "account" => isset($_SESSION["account"]) ? $_SESSION["account"] : NULL,
        "role" => isset($_SESSION["role"]) ? $_SESSION["role"] : NULL,
        "session" => session_id(),
        "token" => isset($_SESSION["token"]) ? $_SESSION["token"] : NULL
    ));
}

$args = vb_request_args();
statusValidateArgs($args);

if ($args["type"] == "status") {
    switch ($args["action"]) {
        case "ping":
            responseSend(TRUE, "status-service pong", "graph", array("service" => "status-service"));
            break;
        case "get":
        case "health":
            vb_token_require_payload();
            statusServiceStateLocal($args);
            break;
        case "dashboard":
        case "stats":
            vb_token_require_payload();
            statusDashboardLocal();
            break;
        case "session":
            vb_token_require_payload();
            statusSessionLocal();
            break;
    }
}

responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "action"), "user", array("variable" => $args["action"]));
