<?php
// public/year_view.php

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/functions.php';

// 0) Get the user_id from session
$user_id = $_SESSION['user_id'];

// 1) Determine which year to display
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// 2) Get user_settings from DB (if you haven’t already)
$pdo = get_db_connection();
$stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = :uid");
$stmt->execute([':uid' => $user_id]);
$user_settings = $stmt->fetch() ?: [];

// 3) Compute or refresh future day scores
compute_day_scores_for_future($user_id, $year, $user_settings);

// 4) Update recommended days
update_recommended_days($user_id, 120);

// year_view.php (only showing the snippet where we display the summary)

// 1) Define your goal
$goal = 120;

// 2) Count how many sessions completed so far (by user_id). 
//    This snippet assumes your cutoff is December 31, 2025.
//    Adjust if needed to match your real logic.
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS completed_count 
    FROM daily_records
    WHERE user_id = :user_id
      AND record_date <= '2025-12-31'
      AND gym_attended = 1
");
$stmt->execute([':user_id' => $user_id]);
$row = $stmt->fetch();
$completed = $row ? (int)$row['completed_count'] : 0;

// 3) Count how many recommended days are currently in the future 
//    (and not attended/impossible). 
//    If you only want recommended days up to 12/31/2025, filter that as well.
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS recommended_count
    FROM daily_records
    WHERE user_id = :user_id
      AND recommended_day = 1
      AND record_date >= CURDATE()
      AND record_date <= '2025-12-31'
");
$stmt->execute([':user_id' => $user_id]);
$row = $stmt->fetch();
$recommended = $row ? (int)$row['recommended_count'] : 0;

// 4) Determine if you’re “on track” 
//    One simple way is: if completed + recommended >= goal, you can still make it.
$remaining_needed = $goal - $completed;
$on_track_msg = '';
if ($completed >= $goal) {
    // Already done
    $on_track_msg = "Congratulations! You’ve already met your $goal-day goal!";
} else {
    // We still need to see if recommended covers the gap
    if ($recommended >= $remaining_needed) {
        $on_track_msg = "You're on track to meet (or exceed) your goal!";
    } else {
        $on_track_msg = "You're behind schedule—consider adding more gym days!";
    }
}

// 5) Display the summary
echo "<p><strong>Your Goal:</strong> $goal total sessions.<br>";
echo "So far, you've completed <strong>$completed</strong> sessions.<br>";
echo "You have <strong>$recommended</strong> recommended days coming up.<br>";
echo "<strong>$on_track_msg</strong></p>";



// 4) If there's a POST update from an inline form, handle it
//    (We do this before fetching the data so that after reloading, 
//     the new data is reflected.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_date'])) {
    $pdo = get_db_connection();

    $record_date = $_POST['record_date'];
    $readiness_score = trim($_POST['readiness_score']) === '' ? null : (int)$_POST['readiness_score'];
    $gym_attended = isset($_POST['gym_attended']) ? 1 : 0;
    $impossible_day = isset($_POST['impossible_day']) ? 1 : 0;
    $is_school_day = isset($_POST['is_school_day']) ? 1 : 0;

    // Update the daily record
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

    // Optionally recalc the day_score for this record_date 
    // (assuming you have user_settings, consecutive-day logic, etc.)
    // e.g. calculate_day_score($user_id, $record_date, $user_settings);

    // Re-run recommendations so the gold days update immediately
    update_recommended_days($user_id, 120);

    // Refresh the page so the updated data & recommended days are displayed
    header("Location: year_view.php?year=$year");
    exit;
}

// 5) Finally, fetch all daily records for this year
$year_data = get_year_data($user_id, $year);

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
        .recommended  { background-color: gold !important; color: #000; }

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
            $first_day_w = (int)$month_start->format('w'); // 0=Sunday, 6=Saturday
        ?>
        <table class="month-table">
            <tr><th colspan="7"><?php echo $month_name; ?></th></tr>
            <tr>
                <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th>
                <th>Thu</th><th>Fri</th><th>Sat</th>
            </tr>
            <?php
            $day_counter = 1;
            while ($day_counter <= $days_in_month) {
                echo "<tr>";
                for ($col = 0; $col < 7; $col++) {
                    // If we haven't reached the first day of the month yet
                    // or if we've passed the last day, print blank.
                    if (($day_counter == 1 && $col < $first_day_w) 
                        || $day_counter > $days_in_month ) {
                        echo "<td></td>";
                    } else {
                        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day_counter);
                        $rec = $year_data[$date_str] ?? null;

                        // Determine cell color / class
                        $cell_class = '';
                        $score_display = '';

                        if ($rec) {
                            if ($rec['gym_attended'] == 1) {
                                // If you attended, override everything with "attended"
                                $cell_class = 'attended';
                            } elseif ($rec['impossible_day'] == 1) {
                                // If it's impossible, override with "impossible"
                                $cell_class = 'impossible';
                            } elseif ($rec['recommended_day'] == 1) {
                                // If it's recommended, highlight gold
                                $cell_class = 'recommended';
                            } else {
                                // Otherwise color by day_score
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
                        // Show the day_score if we have one
                        if ($score_display !== '') {
                            echo "<div style='font-size:0.8em;'>Score: $score_display</div>";
                        }

                        // Edit link to toggle inline form
                        echo "<div class='edit-link' onclick=\"toggleEditForm('$date_str')\">Edit</div>";

                        // Inline edit form (hidden by default)
                        echo "<div id='edit-form-$date_str' class='edit-form'>";
                        echo "<form method='post'>";
                        echo "<input type='hidden' name='record_date' value='{$date_str}'>";

                        // Readiness
                        $readiness_val = $rec ? ($rec['readiness_score'] ?? '') : '';
                        echo "Rdy: <input type='text' name='readiness_score' value='{$readiness_val}' size='2'><br>";

                        // Attended
                        $checked_attended = ($rec && $rec['gym_attended']) ? "checked" : "";
                        echo "<label><input type='checkbox' name='gym_attended' $checked_attended> Attended</label><br>";

                        // Impossible
                        $checked_impossible = ($rec && $rec['impossible_day']) ? "checked" : "";
                        echo "<label><input type='checkbox' name='impossible_day' $checked_impossible> Impossible</label><br>";

                        // School
                        $checked_school = ($rec && $rec['is_school_day']) ? "checked" : "";
                        echo "<label><input type='checkbox' name='is_school_day' $checked_school> School?</label><br>";

                        // Submit
                        echo "<button type='submit'>Save</button>";
                        echo "</form>";
                        echo "</div>";

                        echo "</td>";
                        $day_counter++;
                    }
                }
                echo "</tr>";
            }
            ?>
        </table>
        <?php } // end month loop ?>
    </div>
</body>
</html>
