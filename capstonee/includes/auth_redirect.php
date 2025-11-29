<?php
/**
 * Authentication Redirect Helper
 * Use this function to redirect unauthenticated users to login
 */
function redirect_to_login() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    header("Location: $protocol://$host/index.php");
    exit();
}

