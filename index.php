<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user_email'])) {
    header('Location: /dashboard.php');
} else {
    header('Location: /links/');
}
exit;
