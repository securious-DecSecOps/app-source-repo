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

function userInternalFields() {
    return array("login", "firstname", "lastname", "email", "phone", "password", "account", "creditcard", "birthdate", "amount", "role", "code", "avatar", "about", "otp");
}

function userPublicRow($user) {
    if (!$user) {
        return NULL;
    }
    $row = array();
    foreach ($user as $key => $value) {
        if (!is_int($key)) {
            $row[$key] = $value;
        }
    }
    return $row;
}

function userLookupByIdLocal($id) {
    $user = sqlQuery("SELECT * FROM users WHERE id=?", array($id), "one");
    if (!$user) responseSend(FALSE, sprintf(MSG_USER_FIND_FAIL, $id), "user", NULL);
    responseSend(TRUE, "User lookup", "user", array("user" => userPublicRow($user)));
}

function userLookupByAccountLocal($account) {
    $user = sqlQuery("SELECT * FROM users WHERE account=?", array($account), "one");
    if (!$user) responseSend(FALSE, sprintf(MSG_USER_FIND_FAIL, $account), "user", NULL);
    responseSend(TRUE, "User lookup", "user", array("user" => userPublicRow($user)));
}

function userLookupByLoginLocal($login) {
    $user = sqlQuery("SELECT * FROM users WHERE login=?", array($login), "one");
    if (!$user) responseSend(FALSE, sprintf(MSG_USER_FIND_FAIL, $login), "user", NULL);
    responseSend(TRUE, "User lookup", "user", array("user" => userPublicRow($user)));
}

function userInternalUpdateFieldsLocal($id, $fields) {
    $user = sqlQuery("SELECT * FROM users WHERE id=?", array($id), "one");
    if (!$user) responseSend(FALSE, sprintf(MSG_USER_FIND_FAIL, $id), "user", NULL);

    foreach (userInternalFields() as $field) {
        if (is_array($fields) && array_key_exists($field, $fields)) {
            $user[$field] = $fields[$field];
        }
    }

    sqlQuery("UPDATE users SET login=?, firstname=?, lastname=?, email=?, phone=?, password=?, account=?, creditcard=?, birthdate=?, lastvisit=CURRENT_TIMESTAMP(), amount=?, role=?, code=?, avatar=?, about=?, otp=? WHERE id=?",
        array($user["login"], $user["firstname"], $user["lastname"], $user["email"], $user["phone"], $user["password"],
              $user["account"], $user["creditcard"], $user["birthdate"], $user["amount"], $user["role"], $user["code"],
              $user["avatar"], $user["about"], (int)$user["otp"], $user["id"]), NULL);

    $updated = sqlQuery("SELECT * FROM users WHERE id=?", array($id), "one");
    responseSend(TRUE, sprintf(MSG_USER_UPDATE_SUCCESS, $updated["login"]), "user", array("user" => userPublicRow($updated)));
}

function userBalanceSetLocal($id, $amount) {
    userInternalUpdateFieldsLocal($id, array("amount" => $amount));
}

function userAvatarSetLocal($id, $avatar) {
    userInternalUpdateFieldsLocal($id, array("avatar" => $avatar));
}

function userResetSeedLocal() {
    sqlQuery("DELETE FROM users", NULL, NULL);
    sqlQuery("INSERT INTO users VALUES (1,'j.doe','John','Doe','j.doe@vulnbank.com','+15555555','5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8','DE12345123451234512345','5138-3266-5138-5315','1984-04-04','2017-06-02 11:07:04',760,'admin',845,'uploads/1_profile-1.png','Hi!',0),(2,'j.adams','Jack','Adams','j.adams@vulnbank.com','+14444444','5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8','DE00000111112222233333','4556-7491-4729-3700','1990-05-05','2017-04-29 13:36:55',940,'user',121,'uploads/2_profile-3.png',NULL,1);", NULL, NULL);
    responseSend(TRUE, MSG_DBRESET, "tools", NULL);
}

function userSummaryLocal() {
    $count = sqlQuery("SELECT COUNT(*) AS count FROM users", NULL, "one");
    $rows = sqlQuery("SELECT id, login, account, amount, role FROM users ORDER BY id", NULL, "all");
    responseSend(TRUE, "User summary", "graph", array("users" => (int)$count["count"], "balances" => $rows));
}

$args = vb_prepare_args();

if (!(($args["type"] == "status" && $args["action"] == "ping") ||
      ($args["type"] == "user" && $args["action"] == "login") ||
      ($args["type"] == "code" && $args["action"] == "sms"))) {
    vb_token_require_payload();
}

switch ($args["type"]) {
    case "status":
        switch ($args["action"]) {
            case "ping":
                responseSend(TRUE, "user-service pong", "graph", array("service" => "user-service"));
                break;
        }
        break;
    case "user":
        switch ($args["action"]) {
            case "login":
                userLogin($args["username"], $args["password"], (in_array("code",$args)) ? $args["code"] : "none");
                break;
            case "create":
                is_admin();
                userCreate($args["username"], $args["account"], $args["firstname"], $args["lastname"], $args["password"],
                           $args["email"], $args["phone"], $args["birthdate"], $args["creditcard"]);
                break;
            case "update":
                is_admin();
                userUpdate($args["id"], array("amount" => $args["amount"], "role" => $args["role"], "otp" => (int)$args["otp"]));
                break;
            case "infoupdate":
                userUpdate($_SESSION["id"], array("firstname" => $args["firstname"], "lastname" => $args["lastname"],
                           "birthdate" => $args["birthdate"], "email" => $args["email"],
                           "phone" => $args["phone"], "about" => $args["about"]));
                break;
            case "delete":
                is_admin();
                userDelete($args["id"]);
                break;
            case "forgotpass":
                userPasswordForgot($args["username"], $args["code"], $args["password"]);
                break;
            case "changepass":
                userPasswordChange($args["oldpassword"], $args["newpassword"]);
                break;
            case "check":
                if (isset($args["recipient"]) or isset($args["firstname"]) or isset($args["lastname"]))
                    userCheck($args["recipient"], $args["firstname"], $args["lastname"], $args["creditcard"]);
                break;
            case "statistics":
                graphGetData();
                break;
            case "session":
                sessionCheck();
                break;
            case "ping":
                responseSend(TRUE, "user-service pong", "graph", array("service" => "user-service"));
                break;
            case "lookup_by_id":
                userLookupByIdLocal($args["id"]);
                break;
            case "lookup_by_account":
                userLookupByAccountLocal($args["account"]);
                break;
            case "lookup_by_login":
                userLookupByLoginLocal($args["login"]);
                break;
            case "balance_set":
                userBalanceSetLocal($args["id"], $args["amount"]);
                break;
            case "avatar_set":
                userAvatarSetLocal($args["id"], $args["avatar"]);
                break;
            case "update_fields":
                $fields = isset($args["fields"]) && is_array($args["fields"]) ? $args["fields"] : array();
                foreach (userInternalFields() as $field) {
                    if (array_key_exists($field, $args)) {
                        $fields[$field] = $args[$field];
                    }
                }
                userInternalUpdateFieldsLocal($args["id"], $fields);
                break;
            case "reset_seed_users":
                userResetSeedLocal();
                break;
            case "summary":
                userSummaryLocal();
                break;
        }
        break;
    case "code":
        switch ($args["action"]) {
            case "sms":
                codeGenerate($args["username"], 1);
                break;
        }
        break;
}

responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "action"), "user", array("variable" => $args["action"]));
