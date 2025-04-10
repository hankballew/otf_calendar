<?php
// public/year_view.php

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/functions.php';

// 1) user_id, year, user_settings as usual
$user_id = $_SESSION['user_id'];
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// (Optional) fetch user_settings from DB
$pdo = get_db_connection();
$stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = :uid");
$stmt->execute([':uid' => $user_id]);
$user_settings = $stmt->fetch() ?: [];

// 2) compute day_scores for future & recommended days
compute_day_scores_for_future($user_id, $year, $user_settings);
update_recommended_days($user_id, 120);

// 3) fetch the year data
$year_data = get_year_data($user_id, $year);

// *** NEW: We'll keep a global counter for sessions across the entire year
$days_sequence_counter = 0;

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
        .show { display: block !important; }
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
        <a href="year_view.php?year=<?php echo ($year-1); ?>">&lt;&lt; Previous Year</a> |
        <a href="year_view.php?year=<?php echo ($year+1); ?>">Next Year &gt;&gt;</a> |
        <a href="dashboard.php">Dashboard</a>
    </p>

    <div class="calendar-container">
    <?php
    // 4) We'll loop each month
    for ($month = 1; $month <= 12; $month++) {
        $month_start = new DateTime("$year-$month-01");
        $month_name = $month_start->format('F');
        $days_in_month = (int)$month_start->format('t');

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
                if (($day_counter == 1 && $col < $first_day_w)
                    || $day_counter > $days_in_month) {
                    echo "<td></td>";
                } else {
                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day_counter);
                    $rec = $year_data[$date_str] ?? null;

                    $cell_class = '';
                    $score_display = '';
                    $session_label = '';   // <--- We'll store "Att #x" or "Rec #y" here

                    if ($rec) {
                        if ($rec['gym_attended'] == 1) {
                            // If attended, override everything with "attended"
                            $cell_class = 'attended';

                            // *** increment the sequence counter & note the label
                            $days_sequence_counter++;
                            $session_label = "Att #{$days_sequence_counter}";

                        } elseif ($rec['impossible_day'] == 1) {
                            $cell_class = 'impossible';
                        } elseif (!empty($rec['recommended_day'])) {
                            // recommended day
                            $cell_class = 'recommended';

                            // *** increment the sequence counter & note the label
                            $days_sequence_counter++;
                            $session_label = "Rec #{$days_sequence_counter}";

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
                    // Day number in month
                    echo "<div>{$day_counter}</div>";

                    // If we have a day_score to show
                    if ($score_display !== '') {
                        echo "<div style='font-size:0.8em;'>Score: $score_display</div>";
                    }

                    // If we have a session label (Att # or Rec #)
                    if ($session_label !== '') {
                        echo "<div style='font-size:0.8em;'><strong>$session_label</strong></div>";
                    }

                    // The "Edit" link for your inline form
                    echo "<div class='edit-link' onclick=\"toggleEditForm('$date_str')\">Edit</div>";
                    // Inline form
                    echo "<div id='edit-form-$date_str' class='edit-form'>";
                    echo "<form method='post'>";
                    echo "<input type='hidden' name='record_date' value='{$date_str}'>";

                    $readiness_val = $rec ? ($rec['readiness_score'] ?? '') : '';
                    echo "Rdy: <input type='text' name='readiness_score' value='{$readiness_val}' size='2'><br>";

                    $checked_attended = ($rec && $rec['gym_attended']) ? "checked" : "";
                    echo "<label><input type='checkbox' name='gym_attended' $checked_attended> Attended</label><br>";

                    $checked_impossible = ($rec && $rec['impossible_day']) ? "checked" : "";
                    echo "<label><input type='checkbox' name='impossible_day' $checked_impossible> Impossible</label><br>";

                    $checked_school = ($rec && $rec['is_school_day']) ? "checked" : "";
                    echo "<label><input type='checkbox' name='is_school_day' $checked_school> School?</label><br>";

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
