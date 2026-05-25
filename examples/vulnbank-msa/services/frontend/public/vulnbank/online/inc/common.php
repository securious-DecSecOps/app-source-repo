<?php
include("db.php");

$language = isset($_SESSION["language"]) ? $_SESSION["language"] : "en";
$locale_file = __DIR__ . "/locale/" . basename($language) . ".php";
if (!is_file($locale_file)) {
    $locale_file = __DIR__ . "/locale/en.php";
}
include($locale_file);

error_reporting(E_ALL);
ini_set('display_errors', 1);

function sqlQuery($query, $params, $action) {
    responseSend(FALSE, "Frontend service is DB-less in MSA mode. Use the API gateway.", "attention", NULL);
}

function responseSend($success, $message, $icon, $params) {
    header('Content-Type: application/json');
    if (is_null($params)) $params = array();
    $result = array("status" => ($success ? "success" : "error"),
                    "message" => $message,
                    "icon" => "pe-7s-{$icon}");
    if (!$success) header(':', true, 400);
    die(json_encode(array_merge($result, $params)));
}

function otpCheck($param) {
    return (int)((bool)VB_OTP);
}

function is_admin() {
    if (isset($_SESSION["role"]) && $_SESSION["role"] != "admin") {
        responseSend(FALSE, MSG_ACCESS_DENIED, "user", NULL);
    }
}

function validate($pattern, $variable, $name) {
    if ($name == "Email") {
        $test = filter_var($variable, FILTER_VALIDATE_EMAIL);
    } elseif ($name == "Sender") {
        $test = preg_match($pattern, $variable);
    } else {
        $test = preg_match($pattern, $variable);
    }

    if (!$test && $variable) {
        responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, $name), "user",
            array("variable" => $variable));
    }
}
?>
