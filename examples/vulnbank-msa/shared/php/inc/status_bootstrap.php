<?php
include_once(__DIR__ . "/token_helpers.php");

$session_name = getenv("SESSION_NAME") ?: (isset($_ENV["SESSION_NAME"]) ? $_ENV["SESSION_NAME"] : "PHPSESSID");

if (session_status() == PHP_SESSION_NONE) {
    session_name($session_name);
    session_start();
    if (!isset($_SESSION["language"])) {
        $_SESSION["language"] = "en";
    }
}

include(__DIR__ . "/locale/{$_SESSION["language"]}.php");
error_reporting(E_ALL);
ini_set('display_errors', 1);

function responseSend($success, $message, $icon, $params) {
    header('Content-Type: application/json');
    // Diagnostic only: authentication now uses Authorization Bearer tokens, not this session id.
    header("X-VulnBank-Session: ".session_id());
    if (is_null($params)) $params = array();
    $result = array("status" => ($success ? "success" : "error"),
                    "message" => $message,
                    "icon" => "pe-7s-{$icon}");
    if (!$success) header(':', true, 400);
    die(json_encode(array_merge($result, $params)));
}

function vb_request_args() {
    if (isset($_GET["rest"])) {
        $args = json_decode(file_get_contents('php://input'), true);
        if (!is_array($args)) $args = array();
    } else {
        $args = $_POST;
    }

    if (isset($_SERVER["HTTP_X_VULNBANK_SESSION"]) && !isset($args[session_name()])) {
        $args[session_name()] = $_SERVER["HTTP_X_VULNBANK_SESSION"];
    }

    return $args;
}
