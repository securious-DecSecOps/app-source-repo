<?php
include(__DIR__ . "/db.php");
include_once(__DIR__ . "/token_helpers.php");
include(__DIR__ . "/locale/{$_SESSION["language"]}.php");;
error_reporting(E_ALL);
ini_set('display_errors', 1);

function sqlQuery($query, $params, $action) {
    include(__DIR__ . "/db.php");
    $sql = $db->prepare($query);
    try {
        if (is_null($params)) {
            $sql->execute();
        } else {
            $sql->execute($params);
        }
    } catch(PDOException $exception) {
        responseSend(FALSE, $exception->getMessage(), "attention", NULL);
    }
    switch ($action) {
        case "one":
            return $sql->fetch();
            break;
        case "all":
            return $sql->fetchAll();
            break;
        case "count":
            return $sql->rowCount();
            break;
        case "id":
            return $db->lastInsertId();
            break;
        default:
            return;
            break;
    }
}

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

function otpCheck($param) {
    $row = sqlQuery("SELECT otp FROM users WHERE login=? OR account=?",
                array($param, $param), "one");
    $otp = $row["otp"];
    return (int)((bool)VB_OTP && (bool)$otp);
}

function is_admin() {
    if ($_SESSION["role"] != "admin") responseSend(FALSE, MSG_ACCESS_DENIED, "user", NULL);
}

function validate($pattern, $variable, $name) {
    if ($name == "Email") {
        $test = filter_var($variable, FILTER_VALIDATE_EMAIL);
    } elseif ($name == "Sender") {
        $test = preg_match($pattern, $variable) && ($_SESSION["account"] == $variable);
    } else {
        $test = preg_match($pattern, $variable);
    }

    if (!$test && $variable) {
        responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, $name), "user",
            array("variable" => $variable));
    }
}

function userLogin($login, $password, $code) {
    $user = sqlQuery("SELECT * FROM users WHERE login=? AND password=?",
                    array($login, hash("sha256", $password, false)), "one");
    if($user) {
        if (FALSE && otpCheck($login) && $code != $user["code"]) responseSend(FALSE, MSG_LOGIN_FAILED, "key", NULL);
        session_regenerate_id(true);
        foreach($user as $key => $value) {
            if (!is_int($key)) $_SESSION[$key] = $value;
        }
        $signed = vb_token_issue(array(
            "id" => $user["id"],
            "login" => $user["login"],
            "account" => $user["account"],
            "role" => $user["role"]
        ), vb_token_secret());
        $_SESSION["token"] = $signed;
        sqlQuery("UPDATE users SET lastvisit=CURRENT_TIMESTAMP() WHERE id=?", array($user["id"]), NULL);
        responseSend(TRUE, MSG_LOGIN_SUCCESS, "key", array("session" => session_id(), "token" => $signed));
    } else {
        responseSend(FALSE, MSG_LOGIN_FAILED, "key", NULL);
    }
}

function userCreate($login, $account, $firstname, $lastname, $password, $email, $phone, $birthdate, $creditcard) {
    $count = sqlQuery("SELECT * FROM users WHERE login=? OR account=?", array($login, $account), "count");
    if ($count > 0) responseSend(FALSE, MSG_USER_EXISTS, "user", NULL);
    sqlQuery("INSERT INTO users (login, firstname, lastname, email, phone, password, account, creditcard, birthdate, lastvisit, amount, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, STR_TO_DATE(?, \"%d-%m-%Y\"), CURRENT_TIMESTAMP(), 100, \"user\")",
              array($login, $firstname, $lastname, $email, $phone, hash("sha256",$password), $account, $creditcard, $birthdate), NULL);
    if (function_exists("vb_transaction_deposit")) {
        vb_transaction_deposit($account, 100, session_id());
    }
    responseSend(TRUE, sprintf(MSG_USER_ADD_SUCCESS, $login), "user", NULL);
}

function userUpdate($id, $fields) {
    $user = sqlQuery("SELECT * FROM users WHERE id=?", array($id), "one");
    foreach ($fields as $key => $value) {
        $user[$key] = $value;
    }
    sqlQuery("UPDATE users SET login=?, firstname=?, lastname=?, email=?, phone=?, password=?, account=?, creditcard=?, birthdate=?, lastvisit=CURRENT_TIMESTAMP(), amount=?, role=?, code=?, avatar=?, about=?, otp=? WHERE id=?",
        array($user["login"], $user["firstname"], $user["lastname"], $user["email"],
              $user["phone"], $user["password"], $user["account"], $user["creditcard"],
              $user["birthdate"], $user["amount"], $user["role"], $user["code"],
              $user["avatar"], $user["about"], (int)$user["otp"], $user["id"]), NULL);

    if ($user["amount"] != $_SESSION["amount"] && (string)basename($_SERVER['HTTP_REFERER']) == "users.php") {
        $difference = $user["amount"] - $_SESSION["amount"];
        if ($difference < 0) {
            if (function_exists("vb_transaction_adjustment")) {
                vb_transaction_adjustment("", $user["account"], abs($difference), "Deposit", session_id());
            }
        } else {
            if (function_exists("vb_transaction_adjustment")) {
                vb_transaction_adjustment($user["account"], "", abs($difference), "Withdraw", session_id());
            }
        }
    }
    if ($_SESSION["id"] == $id) {
        foreach ($user as $key => $value) {
            if (in_array($key, $_SESSION)) $_SESSION["key"] = $value;
        }
        responseSend(TRUE, MSG_USER_UPDATE_SELF_SUCCESS, "user", array("balance" => $_SESSION["amount"]));
    } else {
        responseSend(TRUE, sprintf(MSG_USER_UPDATE_SELF_SUCCESS, $user["login"]), "user", array("balance" => $_SESSION["amount"]));
    }
}

function userDelete($id) {
    sqlQuery("DELETE FROM users WHERE id=?", array($id), NULL);
    responseSend(TRUE, MSG_USER_REMOVE_SUCCESS, "user", NULL);
}

function userPasswordForgot($login, $code, $password) {
    if (otpCheck($login)) {
        $count =sqlQuery("SELECT * FROM users WHERE login=? AND code=?",
                          array($login, $code), "count");
        if($count != 1) responseSend(FALSE, MSG_CODE_INVALID, "key", NULL);
    }
    sqlQuery("UPDATE users SET password=? WHERE login=?",
              array(hash("sha256", $password, false), $login), NULL);
    responseSend(TRUE, MSG_PASS_UPDATE_SUCCESS, "key", NULL);
}

function userPasswordChange($oldpassword, $newpassword) {
    $count = sqlQuery("SELECT * FROM users WHERE id=? and password=?",
                       array($_SESSION["id"],hash("sha256", $oldpassword, false)), "count", NULL);
    if ($count == 1) {
        sqlQuery("UPDATE users SET password=? WHERE id=?",
                  array(hash("sha256", $newpassword, false), $_SESSION["id"]), NULL);
        responseSend(TRUE, MSG_PASS_UPDATE_SUCCESS, "key", NULL);
    } else {
        responseSend(FALSE, MSG_PASS_CHECK_FAIL, "key", NULL);
    }
}

function userCheck($account, $firstname, $lastname, $creditcard) {
    include(__DIR__ . "/db.php");
    $query = "SELECT * FROM users WHERE ";
    $array = array("account", "firstname", "lastname", "creditcard");
    foreach ($array as $name) {
        if (isset($$name) && !empty($$name)) {
            if (!preg_match('/WHERE $/',$query)) $query .= " AND";
            $query .= " {$name}='{$$name}'";
        }
    }
    $db_reply = $link->query($query);
    if (!$db_reply) responseSend(FALSE, $link->error, "user", NULL);
    $row = $db_reply->fetch_object();
    if ($row and $db_reply->num_rows == 1 ) {
        responseSend(TRUE, MSG_VALID_SUCCESS, "user", array(
            "firstname" => $row->firstname,
            "lastname" => $row->lastname,
            "recipient" => $row->account,
            "creditcard" => $row->creditcard));
    } else {
        responseSend(FALSE, MSG_VALID_FAIL, "user", NULL);
    }
}

function codeSend($provider, $phone, $message) {
    switch ($provider) {
        case "nexmo":
            $url = 'https://rest.nexmo.com/sms/json?' . http_build_query(
                array('api_key' => NEXMO_API_KEY,
                'api_secret' => NEXMO_API_SECRET,
                "to" => $phone,
                "from" => 900,
                "text" => $message));
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            break;
    }
}

function codeGenerate($login) {
    $row = sqlQuery("SELECT * FROM users WHERE login=?", array($login), "one");
    if ($row) {
        $code = rand(100,999);
        sqlQuery("UPDATE users SET code=? WHERE login=?", array($code, $login), NULL);
        if (otpCheck($login)) {
            codeSend(SMS_API, $row["phone"], "OTP code: {$code}");
            responseSend(TRUE, MSG_CODE_SENT, "key", NULL);
        } else {
            responseSend(TRUE, MSG_CODE_CHANGED, "key", NULL);
        }
    } else {
        responseSend(FALSE, sprintf(MSG_USER_FIND_FAIL, $login), "user", NULL);
    }
}

function graphGetData() {
    if (function_exists("vb_transaction_graph")) {
        $response = vb_transaction_graph($_SESSION["account"], session_id());
        if (is_array($response) && isset($response["status"]) && $response["status"] == "success") {
            responseSend(TRUE, "Graph data", "note2", array(
                "months" => isset($response["months"]) ? $response["months"] : array(),
                "recieved" => isset($response["recieved"]) ? $response["recieved"] : array(),
                "sent" => isset($response["sent"]) ? $response["sent"] : array()
            ));
        }
    }
    responseSend(TRUE, "Graph data", "note2", array("months" => array(), "recieved" => array(), "sent" => array()));
}

function sessionCheck() {
    if (!empty($_SESSION) && isset($_SESSION["login"]) && isset($_SESSION["account"])) {
        responseSend(TRUE, "Session active", "key", array(
            "id" => $_SESSION["id"],
            "login" => $_SESSION["login"],
            "account" => $_SESSION["account"],
            "role" => $_SESSION["role"],
            "amount" => $_SESSION["amount"],
            "session" => session_id(),
            "token" => isset($_SESSION["token"]) ? $_SESSION["token"] : NULL
        ));
    } else {
        responseSend(FALSE, MSG_ACCESS_DENIED, "key", array("session" => session_id()));
    }
}
