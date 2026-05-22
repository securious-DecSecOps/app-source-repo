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
include("inc/service_bootstrap.php");
include("inc/service_client.php");
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

function settingsApplyUserToSession($user) {
    if (!$user) {
        return;
    }
    foreach ($user as $key => $value) {
        if (!is_int($key)) $_SESSION[$key] = $value;
    }
    if (!isset($_SESSION["token"])) {
        $_SESSION["token"] = sha1(uniqid(mt_rand(0,100000)));
    }
}

function settingsBootstrapSessionFromArgs($args) {
    if (!empty($_SESSION) && isset($_SESSION["id"]) && isset($_SESSION["login"])) {
        return;
    }

    $user = NULL;
    if (isset($args["id"]) && $args["id"] !== "") {
        $user = vb_user_lookup_by_id($args["id"], vb_current_session_id());
    } elseif (isset($args["user_id"]) && $args["user_id"] !== "") {
        $user = vb_user_lookup_by_id($args["user_id"], vb_current_session_id());
    } elseif (isset($args["account"]) && $args["account"] !== "") {
        $user = vb_user_lookup_by_account($args["account"], vb_current_session_id());
    } elseif (isset($args["account_number"]) && $args["account_number"] !== "") {
        $user = vb_user_lookup_by_account($args["account_number"], vb_current_session_id());
    } elseif (isset($args["username"]) && $args["username"] !== "") {
        $user = vb_user_lookup_by_login($args["username"], vb_current_session_id());
    } elseif (isset($args["login"]) && $args["login"] !== "") {
        $user = vb_user_lookup_by_login($args["login"], vb_current_session_id());
    }

    settingsApplyUserToSession($user);
}

function settingsRequireSession() {
    if (empty($_SESSION) || !isset($_SESSION["id"]) || !isset($_SESSION["login"])) {
        responseSend(FALSE, MSG_ACCESS_DENIED, "user", array("session" => session_id()));
    }
}

function settingsValidateArgs($args) {
    $validation_template = vb_validation_template();
    foreach ($args as $key => $value) {
        if (isset($validation_template[$key])) {
            validate($validation_template[$key]["regex"], $value, $validation_template[$key]["description"]);
        }
    }
    if (!isset($args["type"])) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "type"), "user", array("variable" => ""));
    if (!isset($args["action"])) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "action"), "user", array("variable" => ""));
}

function settingsBool($value) {
    if (is_bool($value)) return $value ? 1 : 0;
    return in_array(strtolower((string)$value), array("1", "true", "on", "yes"), true) ? 1 : 0;
}

function settingsUserUpdateLocal($id, $fields) {
    $user = vb_user_update_fields($id, $fields, vb_current_session_id());
    if (!$user) responseSend(FALSE, sprintf(MSG_USER_FIND_FAIL, $id), "user", NULL);

    if ((string)$user["id"] === (string)$_SESSION["id"]) {
        settingsApplyUserToSession($user);
    }

    responseSend(TRUE, sprintf(MSG_USER_UPDATE_SUCCESS, $user["login"]), "user",
        array("id" => $user["id"], "login" => $user["login"], "account" => $user["account"], "session" => session_id()));
}

function settingsInfoUpdateLocal($args) {
    settingsRequireSession();
    $id = isset($args["id"]) && $args["id"] !== "" ? $args["id"] : $_SESSION["id"];
    $fields = array();
    foreach (array("firstname", "lastname", "birthdate", "email", "phone", "about", "login", "account", "creditcard", "amount", "role", "avatar", "otp", "code") as $field) {
        if (array_key_exists($field, $args)) {
            $fields[$field] = $field === "otp" ? settingsBool($args[$field]) : $args[$field];
        }
    }
    settingsUserUpdateLocal($id, $fields);
}

function settingsPasswordChangeLocal($args) {
    settingsRequireSession();
    $id = isset($args["id"]) && $args["id"] !== "" ? $args["id"] : $_SESSION["id"];
    $password = isset($args["newpassword"]) ? $args["newpassword"] : (isset($args["password"]) ? $args["password"] : "");
    if ($password === "") responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "password"), "key", array("variable" => ""));

    if ((string)$id === (string)$_SESSION["id"] && isset($args["oldpassword"])) {
        $user = vb_user_lookup_by_id($_SESSION["id"], vb_current_session_id());
        if (!$user || $user["password"] != hash("sha256", $args["oldpassword"], false)) responseSend(FALSE, MSG_PASS_CHECK_FAIL, "key", NULL);
    }

    $updated = vb_user_update_fields($id, array("password" => hash("sha256", $password, false)), vb_current_session_id());
    if (!$updated) responseSend(FALSE, sprintf(MSG_USER_FIND_FAIL, $id), "key", NULL);
    responseSend(TRUE, MSG_PASS_UPDATE_SUCCESS, "key", array("id" => $id, "session" => session_id()));
}

function settingsUpdateLocal($vb_api, $sms_api, $nexmo_api_key, $nexmo_api_secret, $upload_path, $vb_otp) {
    sqlQuery("INSERT INTO settings (param_name, param_value) VALUES ('vb_api',?),('sms_api',?),('nexmo_api_key',?),('nexmo_api_secret',?),('upload_path',?),('vb_otp',?) ON DUPLICATE KEY UPDATE param_name=VALUES(param_name),param_value=VALUES(param_value);",
            array($vb_api, $sms_api, $nexmo_api_key, $nexmo_api_secret, $upload_path, (int)$vb_otp), NULL);
    responseSend(TRUE, MSG_SETTINGS_UPDATE, "tools", NULL);
}

function settingsListLocal() {
    $rows = sqlQuery("SELECT * FROM settings", NULL, "all");
    responseSend(TRUE, "Settings", "tools", array("settings" => $rows));
}

function settingsDbResetLocal() {
    vb_call_user_service("reset_seed_users", array());
    vb_transaction_reset_seed(vb_current_session_id());
    responseSend(TRUE, MSG_DBRESET, "tools", NULL);
}

$args = vb_request_args();

if (isset($args["type"]) && isset($args["action"]) && $args["type"] == "status" && $args["action"] == "ping") {
    responseSend(TRUE, "settings-service pong", "graph", array("service" => "settings-service"));
}

$claims = vb_token_require_payload();
settingsApplyUserToSession($claims);
settingsValidateArgs($args);

switch ($args["type"]) {
    case "settings":
        switch ($args["action"]) {
            case "changelocale":
                $_SESSION["language"] = $args["language"];
                responseSend(TRUE, "success", "flag", NULL);
                break;
            case "get":
            case "list":
                settingsListLocal();
                break;
            case "update":
                settingsUpdateLocal($args["vb_api"], $args["sms_api"], $args["nexmo_api_key"], $args["nexmo_api_secret"], $args["upload_path"], settingsBool($args["vb_otp"]));
                break;
            case "resetdb":
                settingsDbResetLocal();
                break;
            case "userinfo":
            case "infoupdate":
            case "update_user":
                settingsInfoUpdateLocal($args);
                break;
            case "password":
            case "changepass":
                settingsPasswordChangeLocal($args);
                break;
        }
        break;
    case "user":
        switch ($args["action"]) {
            case "infoupdate":
            case "update":
                settingsInfoUpdateLocal($args);
                break;
            case "changepass":
                settingsPasswordChangeLocal($args);
                break;
        }
        break;
}

responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "action"), "user", array("variable" => $args["action"]));
