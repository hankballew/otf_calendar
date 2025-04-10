<?php
require_once __DIR__ . '/../includes/auth.php';

// Log out
session_destroy();

// Redirect to login
header('Location: login.php');
exit;
