<?php
// public/year_view.php

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/functions.php';

// 1) user_id, year, user_settings
$user_id = $_SESSION['user_id'];
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// (Optional) fetch user_settings from DB
$pdo = get_db_connection();
$stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = :uid");
$stmt->execute([':uid' => $user_id]);
$user_settings = $stmt->fetch() ?: [];

// 2) compute day_scores for future & recommended days
compute_day_scores_for_future($user_id, $year, $user_settings);
// For example, pick 120 recommended days
update_recommended_days($user_id, 120);

// 3) fetch the year data (per-day records: readiness, impossible_day, recommended_day, etc.)
$year_data = get_year_data($user_id, $year);

// (Optional) Could compute how many sessions remain to reach a yearly goal, but let's skip that for clarity
$sessions_goal = 120;

// Query how many sessions have been attended so far this year
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt
    FROM daily_records
    WHERE user_id = :uid
      AND gym_attended = 1
      AND YEAR(record_date) = :yr
");
$stmt->execute([
    ':uid' => $user_id,
    ':yr'  => $year
]);
$attended_this_year = (int)$stmt->fetchColumn();

// Now how many are left to reach the goal?
$sessions_left = max(0, $sessions_goal - $attended_this_year);


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
        .score-low    { background-color: #ccffcc; }
        .score-med    { background-color: #ffff99; }
        .score-high   { background-color: #ffcc99; }
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
        .show {
            display: block !important;
        }
        .edit-link {
            font-size: 0.7em;
            cursor: pointer;
            color: blue;
            text-decoration: underline;
        }
    </style>
    <script>
        // toggles the inline form
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
        <a href="year_view.php?year=<?php echo ($year - 1); ?>">&lt;&lt; Previous Year</a> |
        <a href="year_view.php?year=<?php echo ($year + 1); ?>">Next Year &gt;&gt;</a> |
        <a href="dashboard.php">Dashboard</a>
    </p>

    <div class="calendar-container">
    <?php
    // 4) Loop over each month
    for ($month = 1; $month <= 12; $month++) {
        $month_start = new DateTime("$year-$month-01");
        $month_name = $month_start->format('F');
        $days_in_month = (int)$month_start->format('t');

        // 0 = Sunday, 6 = Saturday
        $first_day_w = (int)$month_start->format('w');
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
                // If we're before the first day of this month or past last day, blank cell
                if (($day_counter == 1 && $col < $first_day_w) || ($day_counter > $days_in_month)) {
                    echo "<td></td>";
                } else {
                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day_counter);
                    $rec = $year_data[$date_str] ?? null;

                    $cell_class = '';
                    $score_display = '';

                    if ($rec) {
                        // Reorder the logic so recommended can show up unless overridden
                        if (!empty($rec['gym_attended'])) {
                            // Attended overrides everything
                            $cell_class = 'attended';
                        } elseif (!empty($rec['impossible_day'])) {
                            // If impossible day, override recommended
                            $cell_class = 'impossible';
                        } elseif (!empty($rec['recommended_day'])) {
                            // If recommended_day=1 in DB
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

// Let’s define $label to hold "Att #…" or "Rec #…"
$label = '';

if ($rec) {
    // 1) If day was attended
    if (!empty($rec['gym_attended'])) {
        $cell_class = 'attended';

        // If we still have sessions left in the year’s goal,
        // label this attendance and decrement once
        if ($sessions_left > 0) {
            $label = "Att #{$sessions_left}";
            $sessions_left--;
        }

    // 2) If day is impossible
    } elseif (!empty($rec['impossible_day'])) {
        $cell_class = 'impossible';

    // 3) If day is recommended
    } elseif (!empty($rec['recommended_day'])) {
        // Only highlight if it’s in the future (if you want that)
        // or highlight unconditionally if you want to see it in the past, too.
        if ($date_str >= date('Y-m-d')) {
            $cell_class = 'recommended';

            // label the recommended day if we still have sessions left to fill
            if ($sessions_left > 0) {
                $label = "{$sessions_left} left";
                $sessions_left--;
            }
        } else {
            // It's recommended in the past, you might not label or color it
            // or you can still do $cell_class = 'recommended' if you want
        }

    // 4) Otherwise, color by day_score
    } else {
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

                    // Show day score if we have it
                    if ($score_display !== '') {
                        echo "<div style='font-size:0.8em;'>Score: $score_display</div>";
                    }

if ($label !== '') {
    echo "<div style='font-size:0.8em;'><strong>{$label}</strong></div>";
}


                    // The inline edit link
                    echo "<div class='edit-link' onclick=\"toggleEditForm('$date_str')\">Edit</div>";
                    // Inline form
                    echo "<div id='edit-form-$date_str' class='edit-form'>";
                    echo "<form method='post' action='update_record.php'>"; 
                    // ^^^ you'd presumably post to some "update_record.php" or back to "year_view.php" itself
                    echo "<input type='hidden' name='record_date' value='{$date_str}'>";

                    $readiness_val = $rec ? ($rec['readiness_score'] ?? '') : '';
                    echo "Rdy: <input type='text' name='readiness_score' value='{$readiness_val}' size='2'><br>";

                    $checked_attended   = ($rec && !empty($rec['gym_attended']))     ? "checked" : "";
                    $checked_impossible = ($rec && !empty($rec['impossible_day']))   ? "checked" : "";
                    $checked_school     = ($rec && !empty($rec['is_school_day']))    ? "checked" : "";

                    echo "<label><input type='checkbox' name='gym_attended' {$checked_attended}> Attended</label><br>";
                    echo "<label><input type='checkbox' name='impossible_day' {$checked_impossible}> Impossible</label><br>";
                    echo "<label><input type='checkbox' name='is_school_day' {$checked_school}> School?</label><br>";

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
        <?php
    } // end month loop
    ?>
    </div>
</body>
</html>
