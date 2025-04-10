<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';

$user_id = $_SESSION['user_id'];
$current_year = date('Y');  // or a fixed year like 2025

// 1) Ensure we have baseline rows for every day of this year
ensure_year_records($user_id, $current_year);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file_tmp = $_FILES['csv_file']['tmp_name'];
    if (is_uploaded_file($file_tmp)) {
        $pdo = get_db_connection();
        $handle = fopen($file_tmp, 'r');
        if ($handle) {
            // 2) Parse CSV
            //    Must skip or read the first header row if it exists
            //    Example: "date,readiness_score,gym_attended,impossible_day,is_school_day"
            fgetcsv($handle); // read & discard header row if present

            $upsert_stmt = $pdo->prepare("
                INSERT INTO daily_records 
                  (user_id, record_date, readiness_score, gym_attended, impossible_day, is_school_day)
                VALUES 
                  (:user_id, :record_date, :readiness_score, :gym_attended, :impossible_day, :is_school_day)
                ON DUPLICATE KEY UPDATE
                  readiness_score = VALUES(readiness_score),
                  gym_attended = VALUES(gym_attended),
                  impossible_day = VALUES(impossible_day),
                  is_school_day = VALUES(is_school_day),
                  updated_at = NOW()
            ");

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 5) {
                    continue; // skip invalid lines
                }
                
                list($rec_date, $score, $attended, $impossible, $school) = $row;

                // If your CSV might have empty readiness scores:
                // Convert them to NULL if empty
                $score = trim($score) === '' ? null : (int)$score;

                $upsert_stmt->execute([
                    ':user_id' => $user_id,
                    ':record_date' => $rec_date,
                    ':readiness_score' => $score,
                    ':gym_attended' => (int)$attended,
                    ':impossible_day' => (int)$impossible,
                    ':is_school_day' => (int)$school
                ]);
            }
            fclose($handle);

            $message = "CSV data imported successfully! Baseline rows also ensured for $current_year.";
        } else {
            $message = "Unable to open file.";
        }
    } else {
        $message = "No file uploaded or error reading file.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Import CSV - OTF Calendar</title>
</head>
<body>
    <h1>Import CSV for <?php echo $current_year; ?></h1>
    <?php if ($message): ?>
        <p><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <label>Upload CSV:
            <input type="file" name="csv_file" accept=".csv">
        </label>
        <button type="submit">Import</button>
    </form>

    <p><a href="dashboard.php">Back to Dashboard</a></p>
</body>
</html>
