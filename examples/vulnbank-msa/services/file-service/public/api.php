<?php
include("inc/service_bootstrap.php");
include("inc/service_client.php");
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

function fileApplyUserToSession($user) {
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

function fileBootstrapSessionFromArgs($args) {
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

    fileApplyUserToSession($user);
}

function fileRequireSession() {
    if (empty($_SESSION) || !isset($_SESSION["id"]) || !isset($_SESSION["login"])) {
        responseSend(FALSE, MSG_ACCESS_DENIED, "user", array("session" => session_id()));
    }
}

function fileValidateArgs($args) {
    $validation_template = vb_validation_template();
    foreach ($args as $key => $value) {
        if (isset($validation_template[$key])) {
            validate($validation_template[$key]["regex"], $value, $validation_template[$key]["description"]);
        }
    }
    if (!isset($args["type"])) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "type"), "user", array("variable" => ""));
    if (!isset($args["action"])) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "action"), "user", array("variable" => ""));
}

function fileUploadDirectory() {
    if (defined("UPLOAD_PATH") && UPLOAD_PATH !== "") {
        return UPLOAD_PATH;
    }
    return "uploads";
}

function fileUploadAvatarLocal($args) {
    fileRequireSession();
    if (!isset($_FILES["upload_avatar"])) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "upload_avatar"), "photo", array("variable" => ""));

    $target_user_id = isset($args["id"]) && $args["id"] !== "" ? $args["id"] : $_SESSION["id"];
    $path = fileUploadDirectory();
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }

    $target_file = rtrim($path, "/")."/".$target_user_id."_".$_FILES["upload_avatar"]["name"];
    $moved = move_uploaded_file($_FILES["upload_avatar"]["tmp_name"], $target_file);
    if (!$moved && is_uploaded_file($_FILES["upload_avatar"]["tmp_name"])) {
        $moved = rename($_FILES["upload_avatar"]["tmp_name"], $target_file);
    }
    if (!$moved) {
        responseSend(FALSE, "Upload failed", "photo", array("source" => $target_file));
    }

    chmod($target_file, 0777);
    $updated = vb_user_avatar_set($target_user_id, $target_file, vb_current_session_id());
    if ((string)$target_user_id === (string)$_SESSION["id"] && $updated) {
        $_SESSION["avatar"] = $target_file;
    }

    responseSend(TRUE, MSG_IMAGE_UPLOAD_SUCCESS, "photo",
        array("source" => $target_file, "path" => $target_file, "id" => $target_user_id, "session" => session_id()));
}

function fileListLocal() {
    fileRequireSession();
    $path = fileUploadDirectory();
    $files = array();
    if (is_dir($path)) {
        foreach (scandir($path) as $file) {
            if ($file !== "." && $file !== "..") {
                $files[] = rtrim($path, "/")."/".$file;
            }
        }
    }
    responseSend(TRUE, "Files", "photo", array("files" => $files, "upload_path" => $path));
}

function fileAvatarLocal($args) {
    fileRequireSession();
    $id = isset($args["id"]) && $args["id"] !== "" ? $args["id"] : $_SESSION["id"];
    $row = vb_user_lookup_by_id($id, vb_current_session_id());
    if (!$row) responseSend(FALSE, sprintf(MSG_USER_FIND_FAIL, $id), "user", NULL);
    responseSend(TRUE, "Avatar", "photo", array("id" => $row["id"], "login" => $row["login"], "source" => $row["avatar"]));
}

$args = vb_request_args();
if ($_FILES) {
    $args["type"] = isset($args["type"]) ? $args["type"] : "file";
    $args["action"] = isset($args["action"]) ? $args["action"] : "upload_avatar";
}

if (isset($args["type"]) && isset($args["action"]) && $args["type"] == "status" && $args["action"] == "ping") {
    responseSend(TRUE, "file-service pong", "graph", array("service" => "file-service"));
}

$claims = vb_token_require_payload();
fileApplyUserToSession($claims);
fileValidateArgs($args);

if ($args["type"] == "file") {
    switch ($args["action"]) {
        case "upload":
        case "upload_avatar":
            fileUploadAvatarLocal($args);
            break;
        case "list":
            fileListLocal();
            break;
        case "avatar":
        case "get_avatar":
            fileAvatarLocal($args);
            break;
    }
}

responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "action"), "user", array("variable" => $args["action"]));
