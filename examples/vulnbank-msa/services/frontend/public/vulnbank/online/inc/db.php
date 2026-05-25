<?php
    if (session_id() == '') {
        session_start();
    }

    if (!isset($_SESSION["language"])) {
        $_SESSION["language"] = "en";
    }

    if (isset($_SERVER['HTTP_REFERER'])) {
        $ref = (string)basename($_SERVER['HTTP_REFERER']);
        preg_match("/index_(.*)\.html/", $ref, $match);
        switch ($ref) {
            case "index.html":
                $_SESSION["language"] = "en";
                break;
            default:
                if ($match && isset($match[1]) && $match[1]) {
                    $_SESSION["language"] = $match[1];
                }
                break;
        }
    }

    $frontend_settings = array(
        "NEXMO_API_KEY" => "0000000000",
        "NEXMO_API_SECRET" => "0000000000000000",
        "SMS_API" => "0",
        "UPLOAD_PATH" => "uploads",
        "VB_API" => "none",
        "VB_OTP" => "0"
    );

    foreach ($frontend_settings as $name => $default) {
        if (!defined($name)) {
            $value = getenv($name);
            define($name, $value === false || $value === "" ? $default : $value);
        }
    }
?>
