<?php
/**
 * functions.php
 * Utility & helper functions for the OTF Calendar site.
 */


/**
 * Get the number of consecutive days (streak) the user attended 
 * before a given record_date (not counting that day itself).
 *
 * Example: if user attended on 2025-04-08 and 2025-04-09, 
 * and we're looking at 2025-04-10, the streak is 2.
 */
function get_consecutive_streak($user_id, $record_date) {
    $pdo = get_db_connection();

    // Start from the day before $record_date
    // We'll move backwards day by day until we find a day not attended or no record
    $current_date = new DateTime($record_date);
    $current_date->modify('-1 day'); // the day before

    $streak = 0;

    while (true) {
        $date_str = $current_date->format('Y-m-d');

        // Check if there's a record of gym_attended = 1 for this date
        $stmt = $pdo->prepare("
            SELECT gym_attended
            FROM daily_records
            WHERE user_id = :user_id
              AND record_date = :record_date
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $user_id,
            ':record_date' => $date_str
        ]);

        $row = $stmt->fetch();

        if (!$row || $row['gym_attended'] == 0) {
            // No record found, or user did not attend => streak ends
            break;
        }

        // If attended, increment streak and move another day back
        $streak++;
        $current_date->modify('-1 day');
    }

    return $streak;
}


/**
 * Ensure a row exists for every date of the specified year for this user.
 * If a row already exists for that date, do nothing; otherwise insert a new record with defaults.
 */
function ensure_year_records($user_id, $year) {
    $pdo = get_db_connection();

    // Check if user already has records for this year
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM daily_records 
                           WHERE user_id = :uid 
                             AND YEAR(record_date) = :year");
    $stmt->execute([':uid' => $user_id, ':year' => $year]);
    $row = $stmt->fetch();

    // If we already have records for that year, do nothing 
    // (or you could do partial checks if you want to fill only missing days)
    if ($row && $row['cnt'] > 0) {
        return;
    }

    // Build a date range from Jan 1 to Dec 31 of that year
    $start_date = new DateTime("$year-01-01");
    $end_date   = new DateTime("$year-12-31");

    // We'll do a single prepared statement in a loop.
    // The ON DUPLICATE KEY ensures we don't duplicate if record_date already exists.
    // But since we just checked count=0, probably not needed. It's still good for safety.
    $insert_sql = "
        INSERT INTO daily_records (user_id, record_date, readiness_score, gym_attended, impossible_day, is_school_day)
        VALUES (:user_id, :record_date, NULL, 0, 0, 0)
        ON DUPLICATE KEY UPDATE
            updated_at = NOW()
    ";
    $insert_stmt = $pdo->prepare($insert_sql);

    // Iterate over each day
    $current = $start_date;
    while ($current <= $end_date) {
        $record_date_str = $current->format('Y-m-d');

        $insert_stmt->execute([
            ':user_id' => $user_id,
            ':record_date' => $record_date_str
        ]);

        // Move to next day
        $current->modify('+1 day');
    }
}


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
