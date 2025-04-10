<?php
// public/year_view.php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';

// 1) Determine which year to display
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// 2) Fetch the user_id
$user_id = $_SESSION['user_id'];

// 3) Get data for the year
$year_data = get_year_data($user_id, $year);

// 4) If there's a POST update from an inline form, handle it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_date'])) {
    $pdo = get_db_connection();

    $record_date = $_POST['record_date'];
    $readiness_score = trim($_POST['readiness_score']) === '' ? null : (int)$_POST['readiness_score'];
    $gym_attended = isset($_POST['gym_attended']) ? 1 : 0;
    $impossible_day = isset($_POST['impossible_day']) ? 1 : 0;
    $is_school_day = isset($_POST['is_school_day']) ? 1 : 0;

    $stmt = $pdo->prepare("
        UPDATE daily_records
        SET readiness_score = :readiness_score,
            gym_attended = :gym_attended,
            impossible_day = :impossible_day,
            is_school_day = :is_school_day,
            updated_at = NOW()
        WHERE user_id = :user_id
          AND record_date = :record_date
    ");

    $stmt->execute([
        ':readiness_score' => $readiness_score,
        ':gym_attended' => $gym_attended,
        ':impossible_day' => $impossible_day,
        ':is_school_day' => $is_school_day,
        ':user_id' => $user_id,
        ':record_date' => $record_date
    ]);

    // Optionally recalc day_score, or do it in a cron job. For now let's do it inline:
    // (Assuming you have user_settings loaded somewhere, or we re-fetch them)
    // ...
    // e.g. calculate_day_score($user_id, $record_date, $user_settings);

    // Refresh the page so the updated data is displayed
    header("Location: year_view.php?year=$year");
    exit;
}

// 5) Now build the UI
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Year View - <?php echo $year; ?></title>
    <style>
        .calendar-container {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .month-table {
            border-collapse: collapse;
            width: 300px;
            margin-bottom: 1rem;
        }
        .month-table th {
            text-align: center;
            background-color: #ccc;
        }
        .month-table td {
            width: 40px;
            height: 40px;
            text-align: center;
            vertical-align: middle;
            position: relative;
            border: 1px solid #ddd;
        }
        /* Color classes */
        .score-low    { background-color: #ccffcc; }    /* light green */
        .score-med    { background-color: #ffff99; }    /* yellow */
        .score-high   { background-color: #ffcc99; }    /* light orange */
        .attended     { background-color: #ff6600 !important; color: #fff; }
        .impossible   { background-color: #999999 !important; color: #fff; }
        /* Inline edit form styling */
        .edit-form {
            background: #fff;
            border: 1px solid #aaa;
            padding: 4px;
            position: absolute;
            top: 0;
            left: 0;
            z-index: 999;
            display: none;
            width: 100px;
        }
        .show { display: block !important; }
        .edit-link {
            font-size: 0.7em;
            cursor: pointer;
            color: blue;
            text-decoration: underline;
        }
    </style>
    <script>
        // Minimal JS: toggles the inline form
        function toggleEditForm(recordDate) {
            const formEl = document.getElementById('edit-form-' + recordDate);
            if (formEl) {
                formEl.classList.toggle('show');
            }
        }
    </script>
</head>
<body>
    <h1>Year View - <?php echo $year; ?></h1>
    <p>
        <a href="year_view.php?year=<?php echo ($year-1); ?>">&lt;&lt; Previous Year</a> |
        <a href="year_view.php?year=<?php echo ($year+1); ?>">Next Year &gt;&gt;</a> |
        <a href="dashboard.php">Dashboard</a>
    </p>

    <div class="calendar-container">
        <?php
        // We'll loop through each month (1..12) and build a small calendar table
        for ($month = 1; $month <= 12; $month++) {
            $month_start = new DateTime("$year-$month-01");
            $month_name = $month_start->format('F'); // e.g. "January"
            
            // Let's find out how many days in this month
            $days_in_month = (int)$month_start->format('t');

            // Build an array of weeks -> days
            // We'll do a simple approach: each row is a week, columns: Sun..Sat
            // (If you want Monday as first day, adjust accordingly)
            $first_day_w = (int)$month_start->format('w'); // 0=Sunday, 6=Saturday
            // We may need some blank cells before day 1
        ?>
        <table class="month-table">
            <tr><th colspan="7"><?php echo $month_name; ?></th></tr>
            <tr>
                <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th>
                <th>Thu</th><th>Fri</th><th>Sat</th>
            </tr>
            <?php
            // Start printing rows
            $day_counter = 1;
            $current_week_day = 0; // Sunday
            
            while ($day_counter <= $days_in_month) {
                echo "<tr>";
                // For each of 7 days in the row:
                for ($col = 0; $col < 7; $col++) {
                    // If we haven't reached the first day of the month yet (based on w)
                    // or if we've passed the last day
                    if ( ($day_counter == 1 && $col < $first_day_w) 
                         || $day_counter > $days_in_month ) {
                        // Print empty cell
                        echo "<td></td>";
                    } else {
                        // This is a valid day in the month
                        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day_counter);

                        // Let's fetch the record from $year_data
                        $rec = $year_data[$date_str] ?? null;

                        // Determine cell color / class
                        $cell_class = '';
                        $score_display = '';

                        if ($rec) {
                            // If gym_attended => override color to attended
                            if ($rec['gym_attended'] == 1) {
                                $cell_class = 'attended';
                            } elseif ($rec['impossible_day'] == 1) {
                                $cell_class = 'impossible';
                            } else {
                                // Then color by day_score
                                $score = (float)($rec['day_score'] ?? 0);
                                if ($score < 0.5) {
                                    $cell_class = 'score-low';
                                } elseif ($score < 1.0) {
                                    $cell_class = 'score-med';
                                } else {
                                    $cell_class = 'score-high';
                                }
                                $score_display = round($score, 1);
                            }
                        }

                        echo "<td class=\"{$cell_class}\">";
                        echo "<div>{$day_counter}</div>";
                        // Optionally show the day_score
                        if ($score_display !== '') {
                            echo "<div style='font-size:0.8em;'>Score: $score_display</div>";
                        }

                        // Add a small link to edit
                        echo "<div class='edit-link' onclick=\"toggleEditForm('$date_str')\">Edit</div>";

                        // Inline edit form (hidden by default)
                        echo "<div id='edit-form-$date_str' class='edit-form'>";
                        echo "<form method='post'>";
                        echo "<input type='hidden' name='record_date' value='{$date_str}'>";
                        // readiness
                        $readiness_val = $rec ? $rec['readiness_score'] : '';
                        echo "Rdy: <input type='text' name='readiness_score' value='{$readiness_val}' size='2'><br>";
                        // attended
                        $checked_attended = ($rec && $rec['gym_attended']) ? "checked" : "";
                        echo "<label><input type='checkbox' name='gym_attended' $checked_attended> Attended</label><br>";
                        // impossible
                        $checked_impossible = ($rec && $rec['impossible_day']) ? "checked" : "";
                        echo "<label><input type='checkbox' name='impossible_day' $checked_impossible> Impossible</label><br>";
                        // school
                        $checked_school = ($rec && $rec['is_school_day']) ? "checked" : "";
                        echo "<label><input type='checkbox' name='is_school_day' $checked_school> School?</label><br>";
                        // submit
                        echo "<button type='submit'>Save</button>";
                        echo "</form>";
                        echo "</div>";

                        echo "</td>";
                        $day_counter++;
                    }
                    $current_week_day++;
                }
                echo "</tr>";
            }
            ?>
        </table>
        <?php } // end month loop ?>
    </div>
</body>
</html>
