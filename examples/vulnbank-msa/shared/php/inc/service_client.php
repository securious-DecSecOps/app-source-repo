<?php
include_once(__DIR__ . "/token_helpers.php");

function vb_current_session_id() {
    $session_name = session_name();
    if (isset($_SERVER["HTTP_X_VULNBANK_SESSION"]) && $_SERVER["HTTP_X_VULNBANK_SESSION"] !== "") {
        return $_SERVER["HTTP_X_VULNBANK_SESSION"];
    }
    if (isset($_POST["sid"]) && $_POST["sid"] !== "") {
        return $_POST["sid"];
    }
    if (isset($_GET["sid"]) && $_GET["sid"] !== "") {
        return $_GET["sid"];
    }
    if ($session_name && isset($_POST[$session_name]) && $_POST[$session_name] !== "") {
        return $_POST[$session_name];
    }
    if ($session_name && isset($_GET[$session_name]) && $_GET[$session_name] !== "") {
        return $_GET[$session_name];
    }
    if (session_status() === PHP_SESSION_ACTIVE && session_id() !== "") {
        return session_id();
    }
    return "";
}

function vb_http_status_code($headers) {
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

function vb_call_user_service($action, $payload, $headers=array()) {
    $url = getenv("USER_SERVICE_URL") ?: "http://user-service:8080/api.php";
    return vb_call_form_service($url, "user", $action, $payload, $headers);
}

function vb_call_form_service($url, $type, $action, $payload, $headers=array()) {
    $fields = is_array($payload) ? $payload : array();
    unset($fields["sid"]);
    if (session_name()) {
        unset($fields[session_name()]);
    }
    $fields["type"] = $type;
    $fields["action"] = $action;

    $body = http_build_query($fields);
    $header_lines = array(
        "Content-Type: application/x-www-form-urlencoded",
        "Content-Length: ".strlen($body)
    );
    $token = vb_token_from_request();
    if ($token) {
        $header_lines[] = "Authorization: Bearer ".$token;
    }
    foreach ($headers as $header) {
        $header_lines[] = $header;
    }

    $context = stream_context_create(array(
        "http" => array(
            "method" => "POST",
            "header" => implode("\r\n", $header_lines)."\r\n",
            "content" => $body,
            "timeout" => 10,
            "ignore_errors" => true
        )
    ));

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return NULL;
    }

    $status_code = isset($http_response_header) ? vb_http_status_code($http_response_header) : 0;
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return NULL;
    }
    $decoded["_http_status"] = $status_code;
    return $decoded;
}

function vb_call_transaction_service($action, $payload, $headers=array()) {
    $url = getenv("TRANSACTION_SERVICE_URL") ?: "http://transaction-service:8080/api.php";
    return vb_call_form_service($url, "transaction", $action, $payload, $headers);
}

function vb_response_user_or_null($response) {
    if (!is_array($response)) {
        return NULL;
    }
    if (isset($response["status"]) && $response["status"] !== "success") {
        return NULL;
    }
    if (isset($response["user"]) && is_array($response["user"])) {
        return $response["user"];
    }
    return NULL;
}

function vb_user_lookup_by_id($id, $sid) {
    $response = vb_call_user_service("lookup_by_id", array("id" => $id));
    return vb_response_user_or_null($response);
}

function vb_user_lookup_by_account($account, $sid) {
    $response = vb_call_user_service("lookup_by_account", array("account" => $account));
    return vb_response_user_or_null($response);
}

function vb_user_lookup_by_login($login, $sid) {
    $response = vb_call_user_service("lookup_by_login", array("login" => $login));
    return vb_response_user_or_null($response);
}

function vb_user_balance_set($id, $amount, $sid) {
    $response = vb_call_user_service("balance_set", array("id" => $id, "amount" => $amount));
    return vb_response_user_or_null($response);
}

function vb_user_avatar_set($id, $avatar, $sid) {
    $response = vb_call_user_service("avatar_set", array("id" => $id, "avatar" => $avatar));
    return vb_response_user_or_null($response);
}

function vb_user_update_fields($id, $fields, $sid) {
    $payload = array("id" => $id, "fields" => $fields);
    $response = vb_call_user_service("update_fields", $payload);
    return vb_response_user_or_null($response);
}

function vb_transaction_summary($sid) {
    return vb_call_transaction_service("summary", array());
}

function vb_transaction_reset_seed($sid) {
    return vb_call_transaction_service("reset_seed_transactions", array());
}

function vb_transaction_deposit($account, $amount, $sid) {
    return vb_call_transaction_service("deposit", array("account" => $account, "amount" => $amount));
}

function vb_transaction_graph($account, $sid) {
    return vb_call_transaction_service("graph", array("account" => $account));
}

function vb_transaction_adjustment($from_account, $to_account, $amount, $comment, $sid) {
    return vb_call_transaction_service("adjustment", array(
        "from_user" => $from_account,
        "to_user" => $to_account,
        "amount" => $amount,
        "comment" => $comment
    ));
}
