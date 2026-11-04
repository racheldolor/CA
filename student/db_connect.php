<?php
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDBConnection();
} catch (Throwable $e) {
    error_log('Database connection error: ' . $e->getMessage());
    die('Database connection failed. Please try again later.');
}
?>