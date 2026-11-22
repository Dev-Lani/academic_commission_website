<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;