<?php
session_start();
require_once 'db.php';
//

// Om användaren är inloggad, nollställ login_token i databasen
if (isset($_SESSION['user_id'])) {
    $user_id = intval($_SESSION['user_id']);
    $con->query("UPDATE users SET login_token = NULL WHERE user_id = $user_id");
}

// Rensa session och cookie
$_SESSION = [];
session_destroy();
setcookie('remember_me', '', time() - 3600, "/");

// Tillbaka till login
header("Location: login.php");
exit;
