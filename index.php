<?php
/**
 * index.php
 * Simple landing page. If logged in, go to dashboard. Otherwise, login.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
} else {
    header('Location: login.php');
    exit;
}
