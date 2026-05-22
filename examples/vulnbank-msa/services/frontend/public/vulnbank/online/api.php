<?php
require_once dirname(__DIR__, 2)."/gateway.php";
gateway_handle_request(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH));
