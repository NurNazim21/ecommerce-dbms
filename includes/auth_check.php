<?php
/**
 * auth_check.php  –  include this at the TOP of every protected page,
 * BEFORE db.php and header.php.
 *
 * It starts the session and redirects guests to login.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}