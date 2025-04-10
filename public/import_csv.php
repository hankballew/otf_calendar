<?php
/**
 * import_csv.php
 * Allows CSV upload to insert/update daily_records data in bulk.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/functions.php';

$pdo = get_db_connection();
$user_id = $_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file_tmp = $_FILES['csv_file']['tmp_name'];
    if (is_uploaded_file($file_tmp)) {
        $handle = fopen($file_tmp, 'r');
        if ($handle) {
            // Assuming CSV has headers: date, readiness_score, gym_attended, impossible_day, is_school_day
            // e.g., 2025-04-10,85,1,0,0
            fgetcsv($handle); // read & discard header row

            $stmt = $pdo->prepare("
                INSERT INTO daily_records (user_id, record_date, readiness_score, gym_attended, impossible_day, is_school_day)
                VALUES (:user_id, :record_date, :readiness_score, :gym_attended, :impossible_day, :is_school_day)
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

                $stmt->execute([
                    ':user_id' => $user_id,
                    ':record_date' => $rec_date,
                    ':readiness_score' => (int)$score,
                    ':gym_attended' => (int)$attended,
                    ':impossible_day' => (int)$impossible,
                    ':is_school_day' => (int)$school
                ]);
            }
            fclose($handle);

            // Recalc mean & std dev if desired. Example only (not a full recalc):
            // ...
            $message = "CSV data imported successfully!";
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
    <h1>Import CSV</h1>
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
