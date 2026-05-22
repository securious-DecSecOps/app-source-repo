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

function transactionApplyUserToSession($user) {
    if (!$user) {
        return;
    }
    foreach($user as $key => $value) {
        if (!is_int($key)) $_SESSION[$key] = $value;
    }
    if (!isset($_SESSION["token"])) {
        $_SESSION["token"] = sha1(uniqid(mt_rand(0,100000)));
    }
}

function transactionSessionId() {
    return vb_current_session_id();
}

function transactionRequireSession() {
    if (empty($_SESSION) || !isset($_SESSION["account"]) || !isset($_SESSION["login"])) {
        responseSend(FALSE, MSG_ACCESS_DENIED, "user", array("session" => session_id()));
    }
}

function transactionBootstrapSessionFromArgs($args) {
    if (!empty($_SESSION) && isset($_SESSION["account"]) && isset($_SESSION["login"])) {
        return;
    }

    $account = NULL;
    if (isset($args["sender"]) && !empty($args["sender"])) {
        $account = $args["sender"];
    } elseif (isset($args["account_number"]) && !empty($args["account_number"])) {
        $account = $args["account_number"];
    } elseif (isset($args["account"]) && !empty($args["account"])) {
        $account = $args["account"];
    }

    if ($account) {
        $user = vb_user_lookup_by_account($account, transactionSessionId());
        transactionApplyUserToSession($user);
    }
}

function transactionValidateArgs($args) {
    $validation_template = vb_validation_template();
    foreach ($args as $key => $value) {
        if (isset($validation_template[$key])) {
            validate($validation_template[$key]["regex"], $value, $validation_template[$key]["description"]);
        }
    }

    if (!empty($_SESSION) && isset($_SESSION["account"])) {
        $row = vb_user_lookup_by_account($_SESSION["account"], transactionSessionId());
        if ($row) {
            $_SESSION["amount"] = $row["amount"];
        }
    }

    if (!isset($args["type"])) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "type"), "user", array("variable" => ""));
    if (!isset($args["action"])) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "action"), "user", array("variable" => ""));
}

function transactionOtpEnabledForUser($user) {
    return $user && defined("VB_OTP") && (bool)VB_OTP && isset($user["otp"]) && (bool)$user["otp"];
}

function transactionApproveInternal($id) {
    $transaction = sqlQuery("SELECT * FROM transactions WHERE id=?", array($id), "one");
    if ($transaction) {
        $from_user = $transaction["from_user"] ? vb_user_lookup_by_account($transaction["from_user"], transactionSessionId()) : NULL;
        $to_user = $transaction["to_user"] ? vb_user_lookup_by_account($transaction["to_user"], transactionSessionId()) : NULL;
        if ($to_user && $from_user) {
            vb_user_balance_set($to_user["id"], $to_user["amount"] + $transaction["amount"], transactionSessionId());
            sqlQuery("UPDATE transactions SET approved=? WHERE id=?",
                    array(1, $transaction["id"]), NULL);
            if (transactionOtpEnabledForUser($from_user)) {
                codeSend("nexmo", $from_user["phone"], "Transfered {$transaction["amount"]}$ to {$to_user["account"]}");
            }
        } elseif ($from_user) {
            vb_user_balance_set($from_user["id"], $from_user["amount"] + $transaction["amount"], transactionSessionId());
        } elseif ($to_user) {
            vb_user_balance_set($to_user["id"], $to_user["amount"] + $transaction["amount"], transactionSessionId());
            sqlQuery("UPDATE transactions SET approved=? WHERE id=?",
                    array(1, $transaction["id"]), NULL);
        }
    }
}

function transactionSendLocal($sender, $recipient, $amount, $comment) {
    if (empty($amount)) responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "amount"), "user", array("variable" => ""));
    if ($_SESSION["amount"] < $amount) responseSend(FALSE, MSG_TRANSACTION_LOWMONEY, "cash", NULL);
    if ($_SESSION["account"] == $recipient) responseSend(FALSE, MSG_TRANSACTION_YOURSELF, "cash", NULL);
    $id = sqlQuery("INSERT INTO transactions (from_user,to_user,amount,timestamp,comment,approved) VALUES (?,?,?,CURRENT_TIMESTAMP(),?,0)",
                    array($sender, $recipient, $amount, $comment), "id");
    $_SESSION["amount"] = $_SESSION["amount"] - $amount;
    $sender_user = vb_user_lookup_by_account($sender, transactionSessionId());
    if ($sender_user) {
        vb_user_balance_set($sender_user["id"], $_SESSION["amount"], transactionSessionId());
    }
    if (transactionOtpEnabledForUser($sender_user)) {
        responseSend(TRUE, MSG_CODE_SENT, "key", array("id" => $id));
    } else {
        $user = vb_user_lookup_by_account($recipient, transactionSessionId());
        transactionApproveInternal($id);
        responseSend(TRUE, sprintf(MSG_TRANSACTION_SUCCESS, $amount, $user ? $user["login"] : $recipient), "cash",
            array("balance" => $_SESSION["amount"], "id" => $id, "session" => session_id()));
    }
}

function transactionVerifyLocal($id, $code) {
    if ($code == "none") {
        transactionApproveInternal($id);
        responseSend(TRUE, sprintf(MSG_TRANSACTION_APPROVED, $id), "cash", array("id" => $id));
    } else {
        $transaction = sqlQuery("SELECT * FROM transactions WHERE id=?", array($id), "one");
        if ($transaction) {
            $user = vb_user_lookup_by_account($transaction["from_user"], transactionSessionId());
            if($user && $user["code"] == $code) {
                transactionApproveInternal($id);
                responseSend(TRUE, sprintf(MSG_TRANSACTION_SUCCESS, $transaction["amount"], $transaction["to_user"]),
                    "cash", array("balance" => $_SESSION["amount"], "id" => $id));
            } else {
                transactionUpdateLocal($id, array("approved" => 3));
                responseSend(FALSE, MSG_TRANSACTION_VERIFY_FAIL, "cash", NULL);
            }
        } else {
            responseSend(FALSE, MSG_TRANSACTION_FIND_FAIL, "cash", NULL);
        }
    }
}

function transactionRemoveFailedLocal () {
    sqlQuery("DELETE FROM transactions WHERE approved=2 AND from_user=?", array($_SESSION["account"]), NULL);
    responseSend(TRUE, MSG_TRANSACTION_REMOVEFAILED, "cash", NULL);
}

function transactionCancelLocal () {
    $rows = sqlQuery("SELECT * FROM transactions WHERE approved=0 AND from_user=?", array($_SESSION["account"]), "all");
    foreach ($rows as $row) {
        $_SESSION["amount"] += $row["amount"];
        vb_user_balance_set($_SESSION["id"], $_SESSION["amount"], transactionSessionId());
        sqlQuery("UPDATE transactions SET approved=3 WHERE id=?", array($row["id"]), NULL);
    }
    responseSend(TRUE, sprintf(MSG_TRANSACTION_CANCELED, count($rows)), "cash", NULL);
}

function transactionUpdateLocal($id, $fields) {
    $transaction = sqlQuery("SELECT * FROM transactions WHERE id=?", array($id), "one");
    foreach ($fields as $key => $value) {
        $transaction[$key] = $value;
    }
    sqlQuery("UPDATE transactions SET from_user=?, to_user=?, amount=?, timestamp=?, comment=?, approved=? WHERE id=?",
        array($transaction["from_user"], $transaction["to_user"], $transaction["amount"], $transaction["timestamp"],
        $transaction["comment"], $transaction["approved"], $transaction["id"]), NULL);
}

function transactionCounterparty($row, $account) {
    if ($account == $row["from_user"]) {
        return empty($row["to_user"]) ? NULL : $row["to_user"];
    }
    return empty($row["from_user"]) ? NULL : $row["from_user"];
}

function transactionHistoryItem($row, $account) {
    $status = ($account == $row["from_user"]);
    $counterparty_account = transactionCounterparty($row, $account);
    $counterparty = $counterparty_account ? vb_user_lookup_by_account($counterparty_account, transactionSessionId()) : NULL;
    $name = $counterparty ? trim($counterparty["firstname"]." ".$counterparty["lastname"]) : NULL;
    $creditcard = $counterparty ? $counterparty["creditcard"] : NULL;
    return array(
        "id" => $row["id"],
        "approved" => $row["approved"],
        "direction" => ($status ? "outgoing" : "incoming"),
        "amount" => $row["amount"],
        "display_amount" => ($status ? "-".$row["amount"] : $row["amount"]),
        "name" => $name,
        "account" => ($status ? (empty($row["to_user"]) ? "Withdraw" : $row["to_user"]) : (empty($row["from_user"]) ? "Deposit" : $row["from_user"])),
        "from_user" => $row["from_user"],
        "to_user" => $row["to_user"],
        "creditcard" => $creditcard,
        "timestamp" => $row["timestamp"],
        "comment" => $row["comment"]
    );
}

function transactionHistoryLocal($account) {
    $rows = sqlQuery("SELECT * FROM transactions WHERE from_user=? or to_user=? ORDER BY timestamp DESC",
        array($account, $account), "all");
    $history = array();
    foreach ($rows as $row) {
        array_push($history, transactionHistoryItem($row, $account));
    }
    responseSend(TRUE, "Transaction history", "cash", array("account" => $account, "transactions" => $history));
}

function transactionRecentLocal($account) {
    $rows = sqlQuery("SELECT * FROM transactions WHERE from_user=? or to_user=? ORDER BY timestamp DESC LIMIT 10",
        array($account, $account), "all");
    $history = array();
    foreach ($rows as $row) {
        array_push($history, transactionHistoryItem($row, $account));
    }
    responseSend(TRUE, "Recent transactions", "cash", array("account" => $account, "transactions" => $history));
}

function transactionBalanceLocal($account) {
    $row = vb_user_lookup_by_account($account, transactionSessionId());
    if ($row) {
        responseSend(TRUE, "Balance", "cash", array(
            "account" => $row["account"],
            "login" => $row["login"],
            "amount" => $row["amount"],
            "creditcard" => $row["creditcard"]
        ));
    } else {
        responseSend(FALSE, MSG_USER_FIND_FAIL, "cash", array("account" => $account));
    }
}

function transactionSummaryLocal() {
    $transactions = sqlQuery("SELECT COUNT(*) AS count FROM transactions", NULL, "one");
    $pending = sqlQuery("SELECT COUNT(*) AS count FROM transactions WHERE approved=0", NULL, "one");
    $failed = sqlQuery("SELECT COUNT(*) AS count FROM transactions WHERE approved=2 OR approved=3", NULL, "one");
    responseSend(TRUE, "Transaction summary", "graph", array(
        "transactions" => (int)$transactions["count"],
        "pending_transactions" => (int)$pending["count"],
        "failed_transactions" => (int)$failed["count"]
    ));
}

function transactionGraphLocal($account) {
    $rows = sqlQuery("SELECT DATE_FORMAT(timestamp,'%m') AS month, SUM(CASE WHEN from_user=? THEN amount ELSE 0 END) AS from_sum, SUM(CASE WHEN to_user=? THEN amount ELSE 0 END) AS to_sum FROM transactions GROUP BY DATE_FORMAT(timestamp,'%Y-%m') LIMIT 10", array($account, $account), "all");
    $months = array();
    $to_sum = array();
    $from_sum = array();
    foreach ($rows as $row) {
        $monthName = DateTime::createFromFormat('!m',$row["month"])->format("M");
        array_push($months, $monthName);
        array_push($to_sum, $row["to_sum"]);
        array_push($from_sum, $row["from_sum"]);
    }
    responseSend(TRUE, "Graph data", "note2",
        array("months" => $months, "recieved" => $to_sum, "sent" => $from_sum));
}

function transactionSeedSql() {
    return "INSERT INTO transactions VALUES (1,NULL,'DE12345123451234512345',500,'2016-10-10 10:01:17','Deposit',1),(2,'DE12345123451234512345','DE00000111112222233333',50,'2016-10-13 10:08:41','Thank you for buying me sandwich',1),(3,'DE12345123451234512345','DE00000111112222233333',35,'2016-10-25 12:15:05','I owed you some',1),(4,'DE12345123451234512345','DE00000111112222233333',100,'2016-10-13 14:12:52','Take that and return when you will have spare money',1),(5,'DE00000111112222233333','DE12345123451234512345',50,'2016-10-20 15:22:13','Partial return of my debt',1),(6,NULL,'DE00000111112222233333',300,'2016-10-17 12:44:04','Deposit',1),(7,'DE00000111112222233333','DE12345123451234512345',50,'2016-10-30 09:06:17','Thank you to borrow me money :)',1),(8,'DE12345123451234512345','DE00000111112222233333',100,'2016-10-31 16:27:16','Happy Halloween!',1),(9,'DE12345123451234512345','DE00000111112222233333',75,'2016-11-05 12:20:40','Thank you for that thing, you know',1),(10,NULL,'DE12345123451234512345',250,'2016-11-10 10:05:10','Deposit',1),(11,'DE00000111112222233333','DE12345123451234512345',50,'2016-11-17 15:16:37','As promised',1),(12,'DE12345123451234512345','DE00000111112222233333',35,'2016-11-25 13:31:27','You are welcome :)',1),(13,NULL,'DE12345123451234512345',150,'2016-12-15 13:23:20','Deposit',1),(14,'DE12345123451234512345','DE00000111112222233333',100,'2016-12-25 12:10:17','Merry Christmas!',1),(15,'DE00000111112222233333','DE12345123451234512345',75,'2016-12-25 17:14:43','Merry Christmas to you too, pal!',1),(16,'DE12345123451234512345','DE00000111112222233333',75,'2016-12-31 14:16:57','Happy New Year!',1),(17,'DE00000111112222233333','DE12345123451234512345',100,'2016-12-31 16:20:22','Wish you great New Year! :)',1),(18,NULL,'DE12345123451234512345',150,'2017-01-01 11:12:22','Deposit',1),(19,NULL,'DE00000111112222233333',250,'2017-01-05 21:17:02','Deposit',1),(20,'DE12345123451234512345',NULL,75,'2017-01-16 18:56:34','Withdraw',1),(21,'DE12345123451234512345','DE00000111112222233333',145,'2017-02-15 13:36:54','Wish you all the best',1),(22,NULL,'DE12345123451234512345',175,'2017-02-20 13:21:02','Deposit',1)";
}

function transactionResetSeedLocal() {
    sqlQuery("DELETE FROM transactions", NULL, NULL);
    sqlQuery(transactionSeedSql(), NULL, NULL);
    responseSend(TRUE, MSG_DBRESET, "tools", NULL);
}

function transactionDepositLocal($account, $amount) {
    $id = sqlQuery("INSERT INTO transactions (from_user,to_user,amount,timestamp,comment,approved) VALUES (NULL,?,?,CURRENT_TIMESTAMP(),'Deposit',1)",
                    array($account, $amount), "id");
    responseSend(TRUE, "Deposit transaction created", "cash", array("id" => $id));
}

function transactionAdjustmentLocal($from_user, $to_user, $amount, $comment) {
    $from_value = $from_user === "" ? NULL : $from_user;
    $to_value = $to_user === "" ? NULL : $to_user;
    $id = sqlQuery("INSERT INTO transactions (from_user,to_user,amount,timestamp,comment,approved) VALUES (?,?,?,CURRENT_TIMESTAMP(),?,1)",
                    array($from_value, $to_value, $amount, $comment), "id");
    responseSend(TRUE, "Adjustment transaction created", "cash", array("id" => $id));
}

$args = vb_request_args();
if (isset($args["type"]) && isset($args["action"]) && $args["type"] == "status" && $args["action"] == "ping") {
    responseSend(TRUE, "transaction-service pong", "graph", array("service" => "transaction-service"));
}

$claims = vb_token_require_payload();
$user = vb_user_lookup_by_account($_SESSION["account"], NULL);
if ($user) {
    $_SESSION["amount"] = $user["amount"];
}
transactionValidateArgs($args);

if ($args["type"] == "transaction") {
    switch ($args["action"]) {
        case "summary":
            transactionSummaryLocal();
            break;
        case "reset_seed_transactions":
            transactionResetSeedLocal();
            break;
        case "deposit":
            transactionDepositLocal($args["account"], $args["amount"]);
            break;
        case "graph":
            $account = isset($args["account"]) ? $args["account"] : (isset($_SESSION["account"]) ? $_SESSION["account"] : "");
            transactionGraphLocal($account);
            break;
        case "adjustment":
            transactionAdjustmentLocal(isset($args["from_user"]) ? $args["from_user"] : NULL,
                                       isset($args["to_user"]) ? $args["to_user"] : NULL,
                                       $args["amount"],
                                       isset($args["comment"]) ? $args["comment"] : "");
            break;
    }
}

transactionRequireSession();

switch ($args["type"]) {
    case "transaction":
        switch ($args["action"]) {
            case "send":
                transactionSendLocal($args["sender"], $args["recipient"], $args["amount"], $args["comment"]);
                break;
            case "transfer":
                $sender = isset($args["sender"]) ? $args["sender"] : $_SESSION["account"];
                transactionSendLocal($sender, $args["recipient"], $args["amount"], isset($args["comment"]) ? $args["comment"] : "");
                break;
            case "verify":
                transactionVerifyLocal($args["id"], ($args["code"]) ? $args["code"] : "none");
                break;
            case "clear":
                transactionRemoveFailedLocal();
                break;
            case "cancel":
                transactionCancelLocal();
                break;
            case "history":
                $account = isset($args["account_number"]) ? $args["account_number"] : (isset($args["account"]) ? $args["account"] : $_SESSION["account"]);
                transactionHistoryLocal($account);
                break;
            case "recent":
                $account = isset($args["account_number"]) ? $args["account_number"] : (isset($args["account"]) ? $args["account"] : $_SESSION["account"]);
                transactionRecentLocal($account);
                break;
            case "balance":
                $account = isset($args["account_number"]) ? $args["account_number"] : (isset($args["account"]) ? $args["account"] : $_SESSION["account"]);
                transactionBalanceLocal($account);
                break;
        }
        break;
}

responseSend(FALSE, sprintf(MSG_VALID_PARAM_FAIL, "action"), "user", array("variable" => $args["action"]));
