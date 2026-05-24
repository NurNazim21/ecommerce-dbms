<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = "127.0.0.1";
$user = "root";
$password = "";           // ← CHANGE THIS to your actual root password if not empty
$database = "ecommerce_db";
$port = 3307;             // Important!

$conn = new mysqli($host, $user, $password, $database, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

//echo "Database Connected Successfully!<br>";  // Temporary line - remove later
?>