<?php
session_start();
$redirect = isset($_GET['type']) && $_GET['type'] === 'admin' ? 'admin/login.php' : 'customer/login.php';

session_unset();
session_destroy();

header('Location: ' . $redirect);
exit;
