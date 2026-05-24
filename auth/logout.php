<?php
// user/logout.php

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Destroy all session data
session_unset();
session_destroy();

// Redirect to home.php (your main shopping page)
header("Location: ../user/home.php");
exit();
?>