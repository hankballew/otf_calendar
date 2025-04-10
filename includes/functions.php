<?php
/**
 * functions.php
 * Utility & helper functions for the OTF Calendar site.
 */

// Example: Connect to DB using PDO
function get_db_connection() {
    require_once __DIR__ . '/../config/config.php';
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}

/**
 * Example function for day score calculation (very simplified)
 * In real usage, you'd incorporate your multipliers from user_settings, etc.
 */
function calculate_day_score($day_of_week, $previous_day_attended, $readiness_z, $urgency_multiplier, $school_day, $user_settings) {
    $day_mult = 1.0; 
    switch ($day_of_week) {
        case 'Monday': $day_mult = $user_settings['day_of_week_monday']; break;
        case 'Tuesday': $day_mult = $user_settings['day_of_week_tuesday']; break;
        case 'Wednesday': $day_mult = $user_settings['day_of_week_wednesday']; break;
        // ... etc.
    }

    // Recovery penalty: if previous day was a gym day
    $recovery_mult = $previous_day_attended ? $user_settings['recovery_penalty_first_day'] : 1.0;

    // Z-score multiplier
    // e.g., 1 + (zscore * factor)
    $zscore_mult = 1.0 + ($readiness_z * $user_settings['zscore_multiplier_factor']);

    // School day multiplier
    $school_mult = $school_day ? $user_settings['school_day_multiplier'] : 1.0;

    // Combine
    $day_score = $day_mult * $recovery_mult * $zscore_mult * $urgency_multiplier * $school_mult;

    // Clamp at 0 if negative
    return max(0, $day_score);
}
