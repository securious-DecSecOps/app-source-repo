<?php
    $db_server = getenv("DB_HOST") ?: (isset($_ENV["DB_HOST"]) ? $_ENV["DB_HOST"] : "vulnbank-db");
    $db_username = getenv("DB_USER") ?: (isset($_ENV["DB_USER"]) ? $_ENV["DB_USER"] : "root");
    $db_password = getenv("DB_PASSWORD") !== false ? getenv("DB_PASSWORD") : (isset($_ENV["DB_PASSWORD"]) ? $_ENV["DB_PASSWORD"] : "");
    $db_name = getenv("DB_NAME") ?: (isset($_ENV["DB_NAME"]) ? $_ENV["DB_NAME"] : "vulnbank");
    $session_name = getenv("SESSION_NAME") ?: (isset($_ENV["SESSION_NAME"]) ? $_ENV["SESSION_NAME"] : "PHPSESSID");

    mysqli_report(MYSQLI_REPORT_OFF);
    $link = new mysqli($db_server, $db_username, $db_password, $db_name);
    if(!$link) {
        echo(mysqli_connect_error());
        die("DB connection error: ".mysqli_connect_error());
    }
    if ($link->connect_error) {
        echo($link->connect_error);
        die("DB connection error: ".$link->connect_error);
    }
    $link->set_charset("utf8mb4");

    if (session_status() == PHP_SESSION_NONE) {
        session_name($session_name);
        session_start();
        if (!isset($_SESSION["language"])) {
            $_SESSION["language"] = "en";
        }
    }

    $db = new PDO('mysql:host='. $db_server .';dbname='. $db_name .';charset=utf8mb4', $db_username, $db_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (isset($_SERVER['HTTP_REFERER'])) {
        $ref = (string)basename($_SERVER['HTTP_REFERER']);
        preg_match("/index_(.*)\.html/",$ref,$match);
        switch ($ref) {
            case "index.html":
                $_SESSION["language"] = "en";
            default:
                if ($match && $match[1]) $_SESSION["language"] = $match[1];
                break;

        }
    }

    foreach (array("VB_OTP", "UPLOAD_PATH", "SMS_API", "VB_API", "NEXMO_API_KEY", "NEXMO_API_SECRET") as $k) {
        if (!defined($k)) {
            $v = getenv($k);
            define($k, $v === false ? "" : $v);
        }
    }
