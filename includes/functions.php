<?php
/**
 * functions.php
 * Utility & helper functions for the OTF Calendar site.
 */

function get_average_readiness_by_day_of_week($user_id) {
    $pdo = get_db_connection();
    
    // We'll look at all historical data, or you could limit to the last year, etc.
    // Using DAYNAME() might vary by locale/timezone, 
    // but typically returns "Monday", "Tuesday", etc.
    $sql = "
        SELECT DAYNAME(record_date) AS dow, 
               AVG(readiness_score) AS avg_readiness
        FROM daily_records
        WHERE user_id = :uid
          AND readiness_score IS NOT NULL
          AND readiness_score > 0
        GROUP BY DAYNAME(record_date)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $user_id]);

    $rows = $stmt->fetchAll();
    // Put them in an associative array, e.g. [ 'Monday' => 74.2, 'Tuesday' => 71.9, ... ]
    $averages = [];
    foreach ($rows as $r) {
        $dow = $r['dow'];  // e.g. "Monday"
        $avg = (float) $r['avg_readiness'];
        $averages[$dow] = $avg;
    }

    return $averages;
}

/**
 * If there's no actual readiness or attendance data for a future day, 
 * we'll assign a simple "day_of_week * school_day" based score 
 * (so it won't stay zero).
 */
function calculate_future_day_score($user_id, $date_str, $user_settings, $dow_averages) {
    $pdo = get_db_connection();

    // Query the record (like before)
    $stmt = $pdo->prepare("SELECT * FROM daily_records 
                           WHERE user_id = :uid AND record_date = :date_str");
    $stmt->execute([':uid' => $user_id, ':date_str' => $date_str]);
    $rec = $stmt->fetch();

    // If no record or it's impossible/attended, return 0
    if (!$rec || $rec['impossible_day'] == 1 || $rec['gym_attended'] == 1) {
        return 0.0;
    }

    // day_of_week base multiplier 
    // (like M/W/F = 1.0, T/Th=0.6, weekend=0.5)
    $dow_name = date('l', strtotime($date_str)); // e.g. "Monday"
    // fallback from user_settings or your hard-coded logic:
    $day_of_week_mult = get_day_of_week_multiplier($dow_name, $user_settings);

    // School day multiplier
    $school_mult = ($rec['is_school_day'] == 1)
        ? ($user_settings['school_day_multiplier'] ?? 1.2)
        : 1.0;

    // *** Here is the new part: predicted readiness ***
    // If we have a direct readiness_score for this future date (somehow), we could use it,
    // otherwise we use the day-of-week average
    $predicted_readiness = $rec['readiness_score'];
    if (empty($predicted_readiness)) {
        // see if we have a day-of-week average
        $dow_avg = $dow_averages[$dow_name] ?? 75; // fallback if missing
        $predicted_readiness = $dow_avg;
    }

    // Let's say your global average readiness is 75 with stdev=10, 
    // or you can store these in user_settings. We do a quick z-score:
    // z = (predicted - 75) / 10
    $global_mean = $user_settings['global_readiness_mean'] ?? 75;
    $global_std  = $user_settings['global_readiness_std']  ?? 10;
    $z = ($predicted_readiness - $global_mean) / $global_std;
    // Then we do the same approach as your normal readiness factor:
    $z_factor = $user_settings['zscore_multiplier_factor'] ?? 0.2;
    $z_mult = 1.0 + ($z * $z_factor);
    if ($z_mult < 0) {
        $z_mult = 0;
    }

    // Combine them
    // e.g. day_score = day_of_week_mult * school_mult * z_mult
    // (No consecutive penalty for un-attended future days, or you can do more logic)
    $score = $day_of_week_mult * $school_mult * $z_mult;
    return $score;
}


/**
 * For each future day from "today" to Dec 31 of the target year:
 *   - If day_score is zero or null, recalc it using calculate_future_day_score().
 *   - Then store the result in daily_records.
 */
function compute_day_scores_for_future($user_id, $year, $user_settings) {
    $pdo = get_db_connection();
    
    // Get the day-of-week averages
    $dow_averages = get_average_readiness_by_day_of_week($user_id);

    // (Same date range logic as before)
    $start_date = new DateTime();
    ...
    $upd_stmt = $pdo->prepare("
        UPDATE daily_records
        SET day_score = :score
        WHERE user_id = :uid 
          AND record_date = :date_str
    ");

    $current = clone $start_date;
    while ($current <= $end_date) {
        $date_str = $current->format('Y-m-d');
        // Now call your new function with the day-of-week readiness approach
        $score = calculate_future_day_score($user_id, $date_str, $user_settings, $dow_averages);

        if ($score > 0) {
            $upd_stmt->execute([
                ':score'    => $score,
                ':uid'      => $user_id,
                ':date_str' => $date_str,
            ]);
        }
        $current->modify('+1 day');
    }
}



/**
 * Updates the recommended_day column for the user so that 
 * the top-scoring future days (enough to reach 120 total) are marked recommended.
 */
function update_recommended_days($user_id, $goal = 120) {
    $pdo = get_db_connection();

    // 1) Count how many sessions already completed (this year, or overall, up to you).
    //    Suppose you're only aiming for 120 in this *specific year*:
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS completed 
        FROM daily_records
        WHERE user_id = :user_id
          AND gym_attended = 1
          AND record_date <= '2025-12-31'  -- or YEAR(record_date)=2025 if you only care about that year
    ");
    $stmt->execute([':user_id' => $user_id]);
    $row = $stmt->fetch();
    $already_attended = $row ? (int)$row['completed'] : 0;

    // 2) Calculate how many are left to meet the 120 goal
    $remaining_needed = max(0, $goal - $already_attended);

    // If we've already met or exceeded the goal, no days need to be recommended
    if ($remaining_needed <= 0) {
        $pdo->prepare("
            UPDATE daily_records
            SET recommended_day = 0
            WHERE user_id = :user_id
        ")->execute([':user_id' => $user_id]);
        return; 
    }

    // 3) Gather all future days with day_score, ignoring any that are attended or impossible
    //    We consider 'today' as the cut-off for "future".
    //    If you want to allow marking "today" too, adjust the comparison.
    $stmt = $pdo->prepare("
        SELECT daily_record_id, record_date, day_score
        FROM daily_records
        WHERE user_id = :user_id
          AND record_date >= CURDATE()
          AND record_date <= '2025-12-31'
          AND gym_attended = 0
          AND impossible_day = 0
          AND day_score > 0
        ORDER BY day_score DESC
    ");
    $stmt->execute([':user_id' => $user_id]);
    $potential_days = $stmt->fetchAll();

    // 4) Mark the top N as recommended_day=1, the rest 0
    //    1) zero out recommended for all future days
    $pdo->prepare("
        UPDATE daily_records
        SET recommended_day = 0
        WHERE user_id = :user_id
          AND record_date >= CURDATE()
          AND record_date <= '2025-12-31'
    ")->execute([':user_id' => $user_id]);

    // 2) pick the top N from the sorted list
    $count = 0;
    foreach ($potential_days as $day) {
        if ($count < $remaining_needed) {
            $upd_stmt = $pdo->prepare("
                UPDATE daily_records
                SET recommended_day = 1
                WHERE daily_record_id = :id
            ");
            $upd_stmt->execute([':id' => $day['daily_record_id']]);
            $count++;
        } else {
            break;
        }
    }
}


/**
 * Fetch all daily_records for a given user & year, 
 * ensuring each day has a record (if you use ensure_year_records()).
 * Return an array keyed by 'YYYY-MM-DD'.
 */
function get_year_data($user_id, $year) {
    $pdo = get_db_connection();

    // If you haven't ensured the year is populated, call ensure_year_records() here
    // ensure_year_records($user_id, $year);

    // Optionally recalc scores if needed, or just fetch whatever is stored
    // For example, we can do a loop from Jan 1 to Dec 31 calling calculate_day_score(),
    // or do a single SELECT if you already store them.

    // Let's do a single query:
    $sql = "
        SELECT record_date, readiness_score, gym_attended, impossible_day, is_school_day, day_score
        FROM daily_records
        WHERE user_id = :user_id
          AND YEAR(record_date) = :year
        ORDER BY record_date
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id' => $user_id,
        ':year' => $year,
    ]);

    $results = $stmt->fetchAll();
    $data = [];

    foreach ($results as $row) {
        $key = $row['record_date'];
        $data[$key] = $row;
    }

    return $data;
}



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
 * Calculate the day score, factoring in consecutive-day recovery penalties.
 * 
 * $user_settings might contain day-of-week multipliers, z-score factors, etc.
 */
function calculate_day_score($user_id, $record_date, $user_settings) {
    // 1) Figure out the day_of_week multiplier
    $day_name = date('l', strtotime($record_date)); // e.g. "Monday"
    $key_for_day = 'day_of_week_' . strtolower($day_name);
    $day_of_week_mult = $user_settings[$key_for_day] ?? 1.0;

    // 2) Get readiness z-score factor
    //    Typically you'd fetch readiness_zscore from daily_records 
    //    or recalc on the fly. For brevity, let's just do a quick query:
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT readiness_score, readiness_zscore, gym_attended, impossible_day, is_school_day
        FROM daily_records
        WHERE user_id = :user_id
          AND record_date = :record_date
        LIMIT 1
    ");
    $stmt->execute([
        ':user_id' => $user_id,
        ':record_date' => $record_date
    ]);
    $rec = $stmt->fetch();

    if (!$rec) {
        // If no record for this day, assume default or 0
        return 0.0;
    }

    // If it's impossible day, score is 0
    if ($rec['impossible_day']) {
        return 0.0;
    }

    // z-score multiplier
    $zscore = isset($rec['readiness_zscore']) ? (float)$rec['readiness_zscore'] : 0.0;
    $zscore_factor = isset($user_settings['zscore_multiplier_factor']) 
        ? (float)$user_settings['zscore_multiplier_factor'] : 0.2;
    $zscore_mult = 1.0 + ($zscore * $zscore_factor);
    if ($zscore_mult < 0) {
        $zscore_mult = 0; // clamp at zero to avoid negative
    }

    // 3) Urgency multiplier (behind / ahead schedule)
    //    For simplicity, let's assume behind => 1.2, on track => 1.0, etc.
    $urgency_mult = 1.0; // you'd query how many sessions done vs left

    // 4) School day multiplier
    $school_day_mult = 1.0;
    if ($rec['is_school_day'] && isset($user_settings['school_day_multiplier'])) {
        $school_day_mult = (float)$user_settings['school_day_multiplier'];
    }

    // 5) Consecutive-day recovery penalty
    //    Let's see how many days in a row were attended before 'record_date'
    $streak = get_consecutive_streak($user_id, $record_date);

    $recovery_mult = 1.0; // default if no consecutive day
    if ($streak === 1) {
        $recovery_mult = (float)($user_settings['recovery_penalty_first_day'] ?? 0.4);
    } elseif ($streak === 2) {
        $recovery_mult = (float)($user_settings['recovery_penalty_second_day'] ?? 0.3);
    } elseif ($streak >= 3) {
        // you can define a third-day penalty or a formula
        $recovery_mult = (float)($user_settings['recovery_penalty_third_day'] ?? 0.2);
    }

    // 6) Combine
    $day_score = $day_of_week_mult 
               * $zscore_mult 
               * $urgency_mult 
               * $school_day_mult 
               * $recovery_mult;

    // clamp to zero if negative
    $day_score = max(0, $day_score);

    // Optionally update daily_records.day_score if you want to store it
    $upd = $pdo->prepare("
        UPDATE daily_records
        SET day_score = :score
        WHERE user_id = :user_id
          AND record_date = :record_date
    ");
    $upd->execute([
        ':score' => $day_score,
        ':user_id' => $user_id,
        ':record_date' => $record_date
    ]);

    return $day_score;
}
