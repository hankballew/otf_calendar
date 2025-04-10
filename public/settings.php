<?php
/**
 * settings.php
 * Allows user to update their day-of-week multipliers, recovery penalties, etc.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/functions.php';

$user_id = $_SESSION['user_id'];

// Fetch existing settings
$pdo = get_db_connection();
$stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = :uid");
$stmt->execute(['uid' => $user_id]);
$settings = $stmt->fetch();

if (!$settings) {
    // Insert default row if none exists
    $pdo->prepare("
        INSERT INTO user_settings (user_id) VALUES (:uid)
    ")->execute(['uid' => $user_id]);
    $stmt->execute(['uid' => $user_id]);
    $settings = $stmt->fetch();
}

// Update logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'day_of_week_monday',
        'day_of_week_tuesday',
        'day_of_week_wednesday',
        'day_of_week_thursday',
        'day_of_week_friday',
        'day_of_week_saturday',
        'day_of_week_sunday',
        'recovery_penalty_first_day',
        'recovery_penalty_second_day',
        'zscore_multiplier_factor',
        'urgency_multiplier_behind',
        'urgency_multiplier_ahead',
        'school_day_multiplier'
    ];

    // Build update query dynamically
    $update_cols = [];
    $params = [':uid' => $user_id];
    foreach ($fields as $f) {
        $update_cols[] = "$f = :$f";
        $params[":$f"] = (double)($_POST[$f] ?? $settings[$f]);
    }

    $sql = "UPDATE user_settings SET " . implode(', ', $update_cols) . ", updated_at = NOW() WHERE user_id = :uid";
    $upd = $pdo->prepare($sql);
    $upd->execute($params);

    header('Location: settings.php');
    exit;
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Settings - OTF Calendar</title>
</head>
<body>
    <h1>Update Your Multipliers</h1>
    <form method="post">
        <label>Monday Multiplier:
            <input type="number" step="0.01" name="day_of_week_monday" value="<?php echo htmlspecialchars($settings['day_of_week_monday']); ?>">
        </label><br>
        <label>Tuesday Multiplier:
            <input type="number" step="0.01" name="day_of_week_tuesday" value="<?php echo htmlspecialchars($settings['day_of_week_tuesday']); ?>">
        </label><br>
        <label>Wednesday Multiplier:
            <input type="number" step="0.01" name="day_of_week_wednesday" value="<?php echo htmlspecialchars($settings['day_of_week_wednesday']); ?>">
        </label><br>
        <label>Thursday Multiplier:
            <input type="number" step="0.01" name="day_of_week_thursday" value="<?php echo htmlspecialchars($settings['day_of_week_thursday']); ?>">
        </label><br>
        <label>Friday Multiplier:
            <input type="number" step="0.01" name="day_of_week_friday" value="<?php echo htmlspecialchars($settings['day_of_week_friday']); ?>">
        </label><br>
        <label>Saturday Multiplier:
            <input type="number" step="0.01" name="day_of_week_saturday" value="<?php echo htmlspecialchars($settings['day_of_week_saturday']); ?>">
        </label><br>
        <label>Sunday Multiplier:
            <input type="number" step="0.01" name="day_of_week_sunday" value="<?php echo htmlspecialchars($settings['day_of_week_sunday']); ?>">
        </label><br>

        <label>Recovery Penalty (First Day):
            <input type="number" step="0.01" name="recovery_penalty_first_day" value="<?php echo htmlspecialchars($settings['recovery_penalty_first_day']); ?>">
        </label><br>
        <label>Recovery Penalty (Second Day):
            <input type="number" step="0.01" name="recovery_penalty_second_day" value="<?php echo htmlspecialchars($settings['recovery_penalty_second_day']); ?>">
        </label><br>

        <label>Z-Score Multiplier Factor:
            <input type="number" step="0.01" name="zscore_multiplier_factor" value="<?php echo htmlspecialchars($settings['zscore_multiplier_factor']); ?>">
        </label><br>

        <label>Urgency Multiplier (Behind):
            <input type="number" step="0.01" name="urgency_multiplier_behind" value="<?php echo htmlspecialchars($settings['urgency_multiplier_behind']); ?>">
        </label><br>
        <label>Urgency Multiplier (Ahead):
            <input type="number" step="0.01" name="urgency_multiplier_ahead" value="<?php echo htmlspecialchars($settings['urgency_multiplier_ahead']); ?>">
        </label><br>

        <label>School Day Multiplier:
            <input type="number" step="0.01" name="school_day_multiplier" value="<?php echo htmlspecialchars($settings['school_day_multiplier']); ?>">
        </label><br>

        <button type="submit">Save</button>
    </form>
    <p><a href="dashboard.php">Return to Dashboard</a></p>
</body>
</html>
