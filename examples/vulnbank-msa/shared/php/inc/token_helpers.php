<?php
function vb_b64url_encode($bin) {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function vb_b64url_decode($s) {
    $padded = strtr($s, '-_', '+/');
    $pad = strlen($padded) % 4;
    if ($pad) {
        $padded .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($padded, true);
}

function vb_token_secret() {
    $secret = getenv("VULNBANK_TOKEN_SECRET");
    return $secret === false || $secret === "" ? "vb-poc-token-secret-change-me" : $secret;
}

function vb_token_issue(array $claims, $secret, $ttl=3600) {
    $claims["iat"] = time();
    $claims["exp"] = time() + $ttl;
    $payload = json_encode($claims);
    $p_b64 = vb_b64url_encode($payload);
    $sig = hash_hmac('sha256', $p_b64, $secret);
    return $p_b64.'.'.$sig;
}

function vb_token_verify($token, $secret) {
    if (!is_string($token) || $token === "") {
        return NULL;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return NULL;
    }
    $p_b64 = $parts[0];
    $sig = $parts[1];
    $expected = hash_hmac('sha256', $p_b64, $secret);
    if (!hash_equals($expected, $sig)) {
        return NULL;
    }
    $payload = vb_b64url_decode($p_b64);
    if ($payload === false) {
        return NULL;
    }
    $claims = json_decode($payload, true);
    if (!is_array($claims)) {
        return NULL;
    }
    if (!isset($claims["exp"]) || time() > (int)$claims["exp"]) {
        return NULL;
    }
    return $claims;
}

function vb_token_from_request() {
    if (isset($_SERVER["HTTP_AUTHORIZATION"]) && preg_match('/^Bearer\s+(.+)$/i', $_SERVER["HTTP_AUTHORIZATION"], $match)) {
        return trim($match[1]);
    }
    if (isset($_SERVER["REDIRECT_HTTP_AUTHORIZATION"]) && preg_match('/^Bearer\s+(.+)$/i', $_SERVER["REDIRECT_HTTP_AUTHORIZATION"], $match)) {
        return trim($match[1]);
    }
    if (isset($_SERVER["HTTP_X_VULNBANK_TOKEN"]) && $_SERVER["HTTP_X_VULNBANK_TOKEN"] !== "") {
        return $_SERVER["HTTP_X_VULNBANK_TOKEN"];
    }
    if (isset($_POST["token"]) && $_POST["token"] !== "") {
        return $_POST["token"];
    }
    if (isset($_GET["token"]) && $_GET["token"] !== "") {
        return $_GET["token"];
    }
    return NULL;
}

function vb_token_require_payload($secret=NULL) {
    $resolved_secret = $secret === NULL ? vb_token_secret() : $secret;
    $token = vb_token_from_request();
    $claims = $token ? vb_token_verify($token, $resolved_secret) : NULL;
    if (!$claims) {
        responseSend(FALSE, MSG_ACCESS_DENIED, "user", array("session" => session_id()));
    }
    foreach (array("id", "login", "account", "role") as $key) {
        if (isset($claims[$key])) {
            $_SESSION[$key] = $claims[$key];
        }
    }
    $_SESSION["token"] = $token;
    return $claims;
}
