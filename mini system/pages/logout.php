<?php
session_start();
$_SESSION = [];
session_destroy();
header('Location: /mini%20system/pages/login.php');
exit;
