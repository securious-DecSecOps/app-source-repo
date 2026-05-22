<?php
include(__DIR__ . "/common.php");

function vb_request_args() {
    if (isset($_GET["xml"])) {
        libxml_disable_entity_loader(false);
        $xml = simplexml_load_string(file_get_contents('php://input'), "SimpleXMLElement", LIBXML_PARSEHUGE | LIBXML_NOENT | LIBXML_NOCDATA);
        $json = json_encode($xml);
        $decoded = json_decode($json, true);
        $args = [];
        foreach ($decoded as $key => $value) {
            if (empty($value)) {
                $args[$key] = "";
            } else {
                if ($key == "currentuser" && $value != $_SESSION["login"])
                    responseSend(FALSE, "User mismatch", "user", NULL);
                $args[$key] = $value;
            }
        }
    } elseif (isset($_GET["rest"])) {
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

function vb_validation_template() {
    return array(
        "about" => array("regex" => "/^.+$/", "description" => "About"),
        "account" => array("regex" => "/^[A-Z]{2}[0-9]{20}$/", "description" => "Account"),
        "amount" => array("regex" => "/^[0-9\.-]+$/", "description" => "Amount"),
        "birthdate" => array("regex" => "/^[0-9]*-[0-9]*-[0-9]*$/", "description" => "Birthdate"),
        "code" => array("regex" => "/^[0-9]{3}$/", "description" => "Code"),
        "creditcard" => array("regex" => "/^[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{4}$/", "description" => "Credit Card"),
        "currentuser" => array("regex" => "/^[a-zA-Z0-9\.-_]*$/", "description" => "Current User"),
        "email" => array("regex" => "", "description" => "Email"),
        "id" => array("regex" => "/^[0-9]*$/", "description" => "ID"),
        "lastname" => array("regex" => "/^[a-zA-Z0-9\.-_']*$/", "description" => "Lastname"),
        "language" => array("regex" => "/^[a-z]*$/", "description" => "Language"),
        "login" => array("regex" => "/^[a-zA-Z0-9\.-_]*$/", "description" => "Login"),
        "nexmo_api_key" => array("regex" => "/^[a-zA-Z0-9]*$/", "description" => "Nexmo API key"),
        "nexmo_api_secret" => array("regex" => "/^[a-zA-Z0-9]*$/", "description" => "SMS API secret"),
        "otp" => array("regex" => "/^[0-9]*$/", "description" => "One Time Password"),
        "phone" => array("regex" => "/^[0-9]*$/", "description" => "Phone"),
        "recipient" => array("regex" => "/^[A-Z]{2}[0-9]{20}$/", "description" => "Recipient"),
        "role" => array("regex" => "/(user|admin)/", "description" => "Role"),
        "sender" => array("regex" => "/^[A-Z]{2}[0-9]{20}$/", "description" => "Sender"),
        "sms_api" => array("regex" => "/^[a-z]*$/", "description" => "SMS API type"),
        "username" => array("regex" => "/^[a-zA-Z0-9\.-_]*$/", "description" => "Username"),
        "upload_path" => array("regex" => "/^[a-zA-Z0-9\/:]*$/", "description" => "Upload path"),
        "vb_api" => array("regex" => "/^[a-z]*$/", "description" => "API type"),
        "vb_otp" => array("regex" => "/^[a-z0-9]*$/", "description" => "One Time Password (OTP)")
    );
}

function vb_prepare_args() {
    $args = vb_request_args();

    if ($_FILES) {
        $args["type"] = "file";
        if ($_FILES["upload_avatar"]) {
            $args["action"] = "upload_avatar";
        }
    }

    $validation_template = vb_validation_template();
    foreach ($args as $key => $value) {
        if (isset($validation_template[$key])) {
            validate($validation_template[$key]["regex"], $value, $validation_template[$key]["description"]);
        }
    }

    if ((getenv("DB_NAME") ?: "") == "vb_user" && !empty($_SESSION) && in_array("account", $_SESSION)) {
        $row = sqlQuery("SELECT * FROM users WHERE account=?", array($_SESSION["account"]), "one");
        if ($row) {
            $_SESSION["amount"] = $row["amount"];
        }
    }

    if (!isset($args["type"])) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "type"), "user", array("variable" => ""));
    if (!isset($args["action"])) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "action"), "user", array("variable" => ""));

    return $args;
}
