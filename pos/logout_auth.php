<?php
session_start();

// Clear only POS authentication, keep the main login session
unset($_SESSION['pos_authenticated']);
unset($_SESSION['pos_auth_time']);
unset($_SESSION['pos_csrf_token']);

// Redirect back to authentication page
header("Location: authenticate.php");
exit();
?>
