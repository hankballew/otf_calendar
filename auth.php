<?php
/**
 * auth.php
 * Authentication helper functions.
 */

session_start();

/**
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Force user to login if not logged in
 */
function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}
