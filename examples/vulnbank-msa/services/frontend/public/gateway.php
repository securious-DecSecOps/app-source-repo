<?php
function gateway_service_urls() {
    return array(
        "auth" => getenv("USER_SERVICE_URL") ?: "http://user-service:8080/api.php",
        "user" => getenv("USER_SERVICE_URL") ?: "http://user-service:8080/api.php",
        "code" => getenv("USER_SERVICE_URL") ?: "http://user-service:8080/api.php",
        "transaction" => getenv("TRANSACTION_SERVICE_URL") ?: "http://transaction-service:8080/api.php",
        "transactions" => getenv("TRANSACTION_SERVICE_URL") ?: "http://transaction-service:8080/api.php",
        "status" => getenv("STATUS_SERVICE_URL") ?: "http://status-service:8080/api.php",
        "file" => getenv("FILE_SERVICE_URL") ?: "http://file-service:8080/api.php",
        "files" => getenv("FILE_SERVICE_URL") ?: "http://file-service:8080/api.php",
        "settings" => getenv("SETTINGS_SERVICE_URL") ?: "http://settings-service:8080/api.php"
    );
}

function gateway_request_args() {
    if ($_FILES) {
        return array_merge(array("type" => "file", "action" => "upload_avatar"), $_POST);
    }
    if (isset($_GET["xml"])) {
        $raw = file_get_contents('php://input');
        preg_match('/<type>([^<]+)<\/type>/', $raw, $type);
        preg_match('/<action>([^<]+)<\/action>/', $raw, $action);
        return array("type" => isset($type[1]) ? $type[1] : NULL, "action" => isset($action[1]) ? $action[1] : NULL);
    }
    if (isset($_GET["rest"]) || preg_match('/application\/json/i', isset($_SERVER["CONTENT_TYPE"]) ? $_SERVER["CONTENT_TYPE"] : "")) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : array();
    }
    return $_POST;
}

function gateway_path_defaults($path) {
    $parts = array_values(array_filter(explode("/", trim($path, "/"))));
    if (count($parts) < 3 || $parts[0] !== "api" || $parts[1] !== "v1") {
        return array();
    }

    $group = $parts[2];
    $action = isset($parts[3]) ? $parts[3] : "";
    switch ($group) {
        case "auth":
            $mapped = array("register" => "create", "login" => "login", "session" => "session", "check" => "check", "sms" => "sms");
            return array("type" => $action === "sms" ? "code" : "user", "action" => isset($mapped[$action]) ? $mapped[$action] : $action);
        case "transactions":
            return array("type" => "transaction", "action" => $action === "" ? "history" : $action);
        case "files":
            $mapped = array("upload" => "upload_avatar", "avatar" => "avatar", "list" => "list");
            return array("type" => "file", "action" => isset($mapped[$action]) ? $mapped[$action] : $action);
        case "settings":
            return array("type" => "settings", "action" => $action === "" ? "get" : $action);
        case "status":
            return array("type" => "status", "action" => $action === "" ? "get" : $action);
    }
    return array();
}

function gateway_target_for($path, $args) {
    $urls = gateway_service_urls();
    $defaults = gateway_path_defaults($path);
    $type = isset($args["type"]) && $args["type"] !== "" ? $args["type"] : (isset($defaults["type"]) ? $defaults["type"] : NULL);
    $action = isset($args["action"]) && $args["action"] !== "" ? $args["action"] : (isset($defaults["action"]) ? $defaults["action"] : NULL);
    if (!$type) {
        return NULL;
    }
    if ($type === "user" && in_array($action, array("infoupdate", "update", "changepass"), true)) {
        return $urls["settings"];
    }
    if (isset($urls[$type])) {
        return $urls[$type];
    }
    return NULL;
}

function gateway_query_string($json_mode) {
    $query = $_GET;
    unset($query["rest"]);
    unset($query["xml"]);
    if ($json_mode) {
        $query["rest"] = "1";
    } elseif (isset($_GET["xml"])) {
        $query["xml"] = "1";
    } elseif (isset($_GET["rest"])) {
        $query["rest"] = "1";
    }
    return $query ? "?".http_build_query($query) : "";
}

function gateway_append_field(&$body, $boundary, $name, $value) {
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
    $body .= (string)$value."\r\n";
}

function gateway_append_file(&$body, $boundary, $name, $file) {
    $filename = isset($file["name"]) ? $file["name"] : "upload";
    $type = isset($file["type"]) && $file["type"] !== "" ? $file["type"] : "application/octet-stream";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
    $body .= "Content-Type: {$type}\r\n\r\n";
    $body .= file_get_contents($file["tmp_name"])."\r\n";
}

function gateway_forward_auth_headers(&$headers) {
    if (isset($_SERVER["HTTP_AUTHORIZATION"]) && $_SERVER["HTTP_AUTHORIZATION"] !== "") {
        $headers[] = "Authorization: ".$_SERVER["HTTP_AUTHORIZATION"];
    } elseif (isset($_SERVER["REDIRECT_HTTP_AUTHORIZATION"]) && $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] !== "") {
        $headers[] = "Authorization: ".$_SERVER["REDIRECT_HTTP_AUTHORIZATION"];
    }
    if (isset($_SERVER["HTTP_X_VULNBANK_TOKEN"]) && $_SERVER["HTTP_X_VULNBANK_TOKEN"] !== "") {
        $headers[] = "X-VulnBank-Token: ".$_SERVER["HTTP_X_VULNBANK_TOKEN"];
    }
}

function gateway_build_body($path) {
    $defaults = gateway_path_defaults($path);
    $content_type = isset($_SERVER["CONTENT_TYPE"]) ? $_SERVER["CONTENT_TYPE"] : "";
    $method = isset($_SERVER["REQUEST_METHOD"]) ? $_SERVER["REQUEST_METHOD"] : "POST";

    if ($_FILES) {
        $fields = array_merge($defaults, $_POST);
        $boundary = "----VulnBankGateway".sha1(uniqid(mt_rand(), true));
        $body = "";
        foreach ($fields as $name => $value) {
            gateway_append_field($body, $boundary, $name, $value);
        }
        foreach ($_FILES as $name => $file) {
            if (is_array($file["tmp_name"])) {
                foreach ($file["tmp_name"] as $index => $tmp_name) {
                    gateway_append_file($body, $boundary, $name, array("tmp_name" => $tmp_name, "name" => $file["name"][$index], "type" => $file["type"][$index]));
                }
            } else {
                gateway_append_file($body, $boundary, $name, $file);
            }
        }
        $body .= "--{$boundary}--\r\n";
        return array($body, "multipart/form-data; boundary={$boundary}", false, $method);
    }

    if (preg_match('/application\/json/i', $content_type)) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        if (!is_array($decoded)) $decoded = array();
        $decoded = array_merge($defaults, $decoded);
        return array(json_encode($decoded), "application/json", true, $method);
    }

    if ($_POST || $defaults) {
        $fields = array_merge($defaults, $_POST);
        return array(http_build_query($fields), "application/x-www-form-urlencoded", false, $method);
    }

    $body = file_get_contents('php://input');
    return array($body, $content_type ?: "application/octet-stream", isset($_GET["rest"]), $method);
}

function gateway_forward($target, $path) {
    list($body, $content_type, $json_mode, $method) = gateway_build_body($path);
    $headers = array("Content-Type: {$content_type}", "Content-Length: ".strlen($body));
    if (isset($_SERVER["HTTP_COOKIE"])) {
        $headers[] = "Cookie: ".$_SERVER["HTTP_COOKIE"];
    }
    if (isset($_SERVER["HTTP_X_VULNBANK_SESSION"])) {
        $headers[] = "X-VulnBank-Session: ".$_SERVER["HTTP_X_VULNBANK_SESSION"];
    }
    gateway_forward_auth_headers($headers);

    $context = stream_context_create(array(
        "http" => array(
            "method" => $method,
            "header" => implode("\r\n", $headers)."\r\n",
            "content" => $body,
            "timeout" => 60,
            "ignore_errors" => true
        )
    ));

    $response = file_get_contents($target.gateway_query_string($json_mode), false, $context);
    $status = 502;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/[0-9.]+\s+([0-9]+)/', $header, $match)) {
                $status = (int)$match[1];
            } elseif (stripos($header, "Content-Type:") === 0 || stripos($header, "Set-Cookie:") === 0 || stripos($header, "X-VulnBank-Session:") === 0) {
                header($header, false);
            }
        }
    }

    if ($response === false) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(array("status" => "error", "message" => "MSA backend request failed"));
        exit;
    }

    http_response_code($status);
    echo $response;
    exit;
}

function gateway_handle_request($path) {
    $args = gateway_request_args();
    $defaults = gateway_path_defaults($path);
    $args = array_merge($defaults, $args);
    $target = gateway_target_for($path, $args);
    if (!$target) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(array("status" => "error", "message" => "Unsupported API route for MSA gateway"));
        exit;
    }
    gateway_forward($target, $path);
}

function gateway_file_service_base() {
    $target = getenv("FILE_SERVICE_URL") ?: "http://file-service:8080/api.php";
    return preg_replace('#/api\.php$#', '', $target);
}

function gateway_proxy_file_request($path) {
    if (strpos($path, "/vulnbank/online/uploads/") === 0) {
        $file_path = "/uploads/".substr($path, strlen("/vulnbank/online/uploads/"));
    } else {
        $file_path = $path;
    }

    $body = file_get_contents('php://input');
    $method = isset($_SERVER["REQUEST_METHOD"]) ? $_SERVER["REQUEST_METHOD"] : "GET";
    $headers = array();
    if (isset($_SERVER["HTTP_COOKIE"])) {
        $headers[] = "Cookie: ".$_SERVER["HTTP_COOKIE"];
    }
    if (isset($_SERVER["HTTP_X_VULNBANK_SESSION"])) {
        $headers[] = "X-VulnBank-Session: ".$_SERVER["HTTP_X_VULNBANK_SESSION"];
    }
    gateway_forward_auth_headers($headers);
    if ($body !== "") {
        $headers[] = "Content-Length: ".strlen($body);
    }

    $context = stream_context_create(array(
        "http" => array(
            "method" => $method,
            "header" => $headers ? implode("\r\n", $headers)."\r\n" : "",
            "content" => $body,
            "timeout" => 60,
            "ignore_errors" => true
        )
    ));

    $response = file_get_contents(gateway_file_service_base().$file_path, false, $context);
    $status = 502;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/[0-9.]+\s+([0-9]+)/', $header, $match)) {
                $status = (int)$match[1];
            } elseif (stripos($header, "Content-Type:") === 0 || stripos($header, "Set-Cookie:") === 0 || stripos($header, "X-VulnBank-Session:") === 0) {
                header($header, false);
            }
        }
    }

    if ($response === false) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(array("status" => "error", "message" => "MSA file proxy request failed"));
        exit;
    }

    http_response_code($status);
    echo $response;
    exit;
}
