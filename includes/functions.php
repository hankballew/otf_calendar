<?php
/**
 * functions.php
 * Utility & helper functions for the OTF Calendar site.
 */

/**
 * compute_day_scores_for_future()
 *
 * Loops from "today" to "year-12-31" for the specified user,
 * assigning a day_score to each future day if it isn't impossible or attended.
 * 
 * The score is based on:
 *   - Day-of-week multiplier (M/W/F vs. T/Th vs. weekend).
 *   - School-day multiplier.
 *   - Predicted readiness from day-of-week historical average.
 *   - An optional z-score approach using global mean/std dev (if in user_settings).
 */
function compute_day_scores_for_future($user_id, $year, $user_settings) {
    // 1) Get a DB connection
    $pdo = get_db_connection();

    // 2) Fetch day-of-week readiness averages, e.g. ["Monday" => 74.2, "Tuesday" => 71.9, ...]
    $dow_averages = get_average_readiness_by_day_of_week($user_id);

    // 3) Determine the date range: from 'today' up to 'year-12-31'.
    //    If "today" is beyond 'year-12-31', the loop won't run.
    $start_date = new DateTime();
    $year_start = new DateTime("$year-01-01");
    if ($start_date < $year_start) {
        // If it's earlier in the calendar than "year-01-01", start from year-01-01
        $start_date = $year_start;
    }
    $end_date = new DateTime("$year-12-31");

    // 4) We'll need a prepared statement to update daily_records.day_score
    $upd_stmt = $pdo->prepare("
        UPDATE daily_records
        SET day_score = :score
        WHERE user_id = :uid 
          AND record_date = :date_str
    ");

    // 5) Some optional global readiness stats (mean/std) from user_settings, or fallback
    $global_mean = $user_settings['global_readiness_mean'] ?? 75;
    $global_std  = $user_settings['global_readiness_std']  ?? 10;
    $z_factor    = $user_settings['zscore_multiplier_factor'] ?? 0.2;

    // 6) Now iterate day-by-day
    $current = clone $start_date;
    while ($current <= $end_date) {
        $date_str = $current->format('Y-m-d');

        // Fetch the row from daily_records
        $stmt = $pdo->prepare("
            SELECT daily_record_id, gym_attended, impossible_day, is_school_day, readiness_score
            FROM daily_records
            WHERE user_id = :uid 
              AND record_date = :date_str
            LIMIT 1
        ");
        $stmt->execute([':uid' => $user_id, ':date_str' => $date_str]);
        $rec = $stmt->fetch();

        // If there's no row at all, or it's flagged impossible or already attended, skip
        // (score remains 0 or NULL)
        if (!$rec || $rec['impossible_day'] == 1 || $rec['gym_attended'] == 1) {
            $current->modify('+1 day');
            continue;
        }

        // 6a) Day-of-week multiplier
        $dow_name = $current->format('l'); // "Monday", "Tuesday", etc.
        $day_of_week_mult = 1.0; // fallback

        // If your user_settings contain day_of_week_xxx, you can do:
        // $key = 'day_of_week_' . strtolower($dow_name); 
        // $day_of_week_mult = $user_settings[$key] ?? 1.0;

        // Or do a simple hard-coded approach:
        if (in_array($dow_name, ['Monday','Wednesday','Friday'])) {
            $day_of_week_mult = 1.0;
        } elseif (in_array($dow_name, ['Tuesday','Thursday'])) {
            $day_of_week_mult = 0.6;
        } else {
            // weekend
            $day_of_week_mult = 0.5;
        }

        // 6b) School day multiplier
        $school_mult = ($rec['is_school_day'] == 1)
            ? ($user_settings['school_day_multiplier'] ?? 1.2)
            : 1.0;

        // 6c) Predicted readiness for this day-of-week
        // If readiness_score is provided for this day (somehow you put it in?), use it
        $predicted_readiness = $rec['readiness_score'];
        if (empty($predicted_readiness)) {
            // Look up the average for that day-of-week
            $dow_avg = $dow_averages[$dow_name] ?? 75; // fallback if no data
            $predicted_readiness = $dow_avg;
        }

        // 6d) Convert predicted readiness into a z-score factor, if desired
        // e.g. z = (readiness - mean) / std
        $z = ($predicted_readiness - $global_mean) / $global_std;
        $z_mult = 1.0 + ($z * $z_factor);
        if ($z_mult < 0) {
            $z_mult = 0; 
        }

        // 6e) Final day_score
        // You can refine this however you want
        $score = $day_of_week_mult * $school_mult * $z_mult;

        // 6f) Store day_score in the DB
        $upd_stmt->execute([
            ':score'    => $score,
            ':uid'      => $user_id,
            ':date_str' => $date_str,
        ]);

        // Move to the next day
        $current->modify('+1 day');
    }
}


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



/**
 * update_recommended_days()
 *
 * 1) Determine how many sessions the user has already attended this year.
 * 2) Determine how many recommended future days already exist in the DB.
 * 3) Figure out how many more recommended days are *actually needed* to reach the goal.
 * 4) If we have more recommended days than needed, unmark the extras.  <-- This is "step 4"
 * 5) If we still need more, find new days to mark as recommended, up to the needed count.
 *
 * @param int $user_id
 * @param int $goal  (e.g. 120 sessions/year)
 */
function update_recommended_days($user_id, $goal)
{
    // 1) How many sessions has the user already attended *this year*?
    $pdo  = get_db_connection();
    $year = date('Y'); // or pass it in as an argument if needed

    $sql = "
        SELECT COUNT(*) AS cnt
        FROM daily_records
        WHERE user_id = :uid
          AND YEAR(record_date) = :yr
          AND gym_attended = 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uid' => $user_id,
        ':yr'  => $year
    ]);
    $attended = (int)$stmt->fetchColumn();  // how many already attended

    // 2) Figure out how many future recommended days currently exist
    //    (days with recommended_day=1 that haven't been attended or flagged impossible).
    //    We'll keep them if we still need them, or remove them if they're extras.
    $sql = "
        SELECT daily_record_id, record_date
        FROM daily_records
        WHERE user_id = :uid
          AND YEAR(record_date) = :yr
          AND record_date >= CURDATE()   -- only look at future (or today)
          AND recommended_day = 1
          AND gym_attended = 0
          AND impossible_day = 0
        ORDER BY record_date ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uid' => $user_id,
        ':yr'  => $year
    ]);
    $existing_recommended = $stmt->fetchAll(); // array of [daily_record_id, record_date...]

    // 3) How many total sessions are needed to reach the yearly goal?
    $sessions_needed = $goal - $attended;
    if ($sessions_needed < 0) {
        $sessions_needed = 0; // if user already exceeded the goal
    }

    $already_recommended_count = count($existing_recommended);

    // 4) If we already have enough recommended future days to meet the goal,
    //    we can unmark the *excess* so you don't exceed the goal with recommended days.
    //    This is the "Step 4" logic you asked about.
    if ($already_recommended_count >= $sessions_needed) {
        // If you prefer to keep exactly the earliest $sessions_needed recommended, 
        // unmark the rest:
        $excess = $already_recommended_count - $sessions_needed;
        if ($excess > 0) {
            // We'll keep the earliest $sessions_needed from $existing_recommended,
            // and unmark the remainder (the "excess").
            //
            // array_slice($array, $offset, $length) -> keep [0..($sessions_needed-1)] 
            // unmark the ones from [$sessions_needed .. end].
            $excessItems = array_slice($existing_recommended, $sessions_needed);

            foreach ($excessItems as $row) {
                $id = (int)$row['daily_record_id'];
                $pdo->query("UPDATE daily_records SET recommended_day=0 WHERE daily_record_id=$id");
            }
        }

        // Since we already have enough recommended days, we can stop here.
        return;
    }

    // 5) Otherwise, we need more recommended days.
    //    $still_needed = how many more recommended days beyond the existing ones
    $still_needed = $sessions_needed - $already_recommended_count;

    // Let's pick future days that are not recommended or attended or impossible yet.
    // Then mark them as recommended, up to $still_needed.
    // For best effect, you might want to order by day_score DESC so the highest scoring
    // days get recommended first. Alternatively, order by record_date ASC to pick chronologically.
    $sql = "
        SELECT daily_record_id
        FROM daily_records
        WHERE user_id = :uid
          AND YEAR(record_date) = :yr
          AND record_date >= CURDATE()
          AND gym_attended = 0
          AND impossible_day = 0
          AND recommended_day = 0
        ORDER BY day_score DESC, record_date ASC 
        -- or maybe just ORDER BY record_date ASC if you prefer chronological picking
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uid' => $user_id,
        ':yr'  => $year
    ]);
    $candidates = $stmt->fetchAll(); // potential new recommended days

    $assignedCount = 0;
    foreach ($candidates as $row) {
        if ($assignedCount >= $still_needed) {
            break;
        }
        $id = (int)$row['daily_record_id'];
        $pdo->query("UPDATE daily_records SET recommended_day=1 WHERE daily_record_id=$id");
        $assignedCount++;
    }

    // Done—any leftover days remain un-recommended because we only needed $still_needed more.
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
        SELECT record_date, readiness_score, gym_attended, impossible_day, is_school_day, day_score, recommended_day
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
