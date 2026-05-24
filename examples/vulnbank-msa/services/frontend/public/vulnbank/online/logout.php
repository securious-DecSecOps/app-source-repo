<?php
    if(isset($_SESSION["language"])) $language = $_SESSION["language"];
    session_unset();
    session_destroy();
    session_write_close();
    setcookie(session_name(),"",0,"/");
    if (session_id() == '') {
        session_start();
    } else {
        session_regenerate_id(true);
    }
    $_SESSION["language"] = $language;
?>
<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <title>Logging out…</title>
  </head>
  <body>
    <script>
      try { localStorage.removeItem("vb_token"); } catch (e) {}
      window.location.replace("login.php");
    </script>
    <noscript>
      <meta http-equiv="refresh" content="0;url=login.php">
    </noscript>
  </body>
</html>
