<?php
/**
 * dashboard.php
 * Show user’s calendar, day scores, etc.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/functions.php';

$user_id = $_SESSION['user_id'] ?? 0;
$pdo = get_db_connection();

// Fetch user settings
$stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = :uid");
$stmt->execute(['uid' => $user_id]);
$user_settings = $stmt->fetch() ?: [];

// Basic query for daily records in next 30 days (example)
$stmt = $pdo->prepare("
    SELECT record_date, readiness_score, readiness_zscore, gym_attended, impossible_day, is_school_day
    FROM daily_records
    WHERE user_id = :uid 
      AND record_date >= CURDATE()
      AND record_date < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY record_date ASC
");
$stmt->execute(['uid' => $user_id]);
$records = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>OTF Calendar - Dashboard</title>
    <style>
        .low-score { background-color: lightgreen; }
        .medium-score { background-color: yellow; }
        .high-score { background-color: orange; }
        .attended { background-color: #ff6600; } /* Orange for OTF */
        .impossible { background-color: #ccc; }
    </style>
</head>
<body>
    <h1>OTF Calendar Dashboard</h1>
    <p><a href="logout.php">Logout</a> | <a href="settings.php">Settings</a> | <a href="import_csv.php">Import CSV</a></p>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr>
            <th>Date</th>
            <th>Readiness</th>
            <th>Gym Attended?</th>
            <th>Impossible Day?</th>
            <th>Score (Example Calc)</th>
        </tr>
        <?php foreach ($records as $row): 
            // Example day-of-week
            $day_of_week = date('l', strtotime($row['record_date']));

            // For demonstration, do a quick calc using placeholders
            // Real code: you'd check "did we attend the previous day?"
            $previous_day_attended = false; 

            // Calculate zscore multiplier
            $z_mult = 1.0;
            if (isset($row['readiness_zscore'])) {
                $z_mult = 1.0 + ($row['readiness_zscore'] * ($user_settings['zscore_multiplier_factor'] ?? 0.2));
            }

            // Simple urgency - let's pretend we are behind
            $urgency_mult = $user_settings['urgency_multiplier_behind'] ?? 1.2;

            // recovery penalty if needed, skipping for example
            $recovery_mult = 1.0;

            // day_of_week multiplier
            $dow_mult = 1.0;
            if (!empty($user_settings)) {
                $key = 'day_of_week_' . strtolower($day_of_week);
                if (isset($user_settings[$key])) {
                    $dow_mult = $user_settings[$key];
                }
            }

            // school day multiplier
            $school_mult = $row['is_school_day'] ? ($user_settings['school_day_multiplier'] ?? 1.2) : 1.0;

            // Combine
            $score = $dow_mult * $recovery_mult * $z_mult * $urgency_mult * $school_mult;
            $score = max(0, $score);

            // Determine color class
            $class = '';
            if ($row['gym_attended']) {
                $class = 'attended';
            } elseif ($row['impossible_day']) {
                $class = 'impossible';
            } else {
                if ($score < 0.5) $class = 'low-score';
                else if ($score < 1.2) $class = 'medium-score';
                else $class = 'high-score';
            }
        ?>
        <tr class="<?php echo $class; ?>">
            <td><?php echo htmlspecialchars($row['record_date']); ?></td>
            <td><?php echo htmlspecialchars($row['readiness_score'] ?: 'N/A'); ?></td>
            <td><?php echo $row['gym_attended'] ? 'Yes' : 'No'; ?></td>
            <td><?php echo $row['impossible_day'] ? 'Yes' : 'No'; ?></td>
            <td><?php echo round($score, 3); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
