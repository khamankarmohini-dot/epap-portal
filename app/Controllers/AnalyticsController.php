<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use PDO;

class AnalyticsController
{
    public function studentAnalytics(Request $req): void
    {
        $pdo = Database::getConnection();
        $uid = (int) Auth::userId();

        $sStmt = $pdo->prepare('SELECT * FROM students WHERE user_id = ? LIMIT 1');
        $sStmt->execute([$uid]);
        $student = $sStmt->fetch();
        if (!$student) {
            Response::error('Student profile not found.', 404);
        }
        $stuId = (int) $student['id'];

        // 1. Semester SGPA history (published only) + Dept Average
        $histStmt = $pdo->prepare(
            "SELECT sm.semester, MAX(sm.sgpa) AS student_sgpa
             FROM semester_marks sm
             JOIN exam_results_release er ON sm.exam_id = er.id
             WHERE sm.student_id = ? AND er.status IN ('published','locked')
             GROUP BY sm.semester
             ORDER BY sm.semester ASC"
        );
        $histStmt->execute([$stuId]);
        $histRows = $histStmt->fetchAll();

        $deptStmt = $pdo->query(
            "SELECT sm.semester, ROUND(AVG(sm.sgpa), 2) AS dept_avg
             FROM semester_marks sm
             JOIN exam_results_release er ON sm.exam_id = er.id
             WHERE er.status IN ('published','locked')
             GROUP BY sm.semester"
        );
        $deptMap = [];
        foreach ($deptStmt->fetchAll() as $d) {
            $deptMap[(int) $d['semester']] = (float) $d['dept_avg'];
        }

        $semesters = array_map(fn($r) => [
            'semester' => (int) $r['semester'],
            'label' => 'Sem ' . $r['semester'],
            'student_sgpa' => (float) $r['student_sgpa'],
            'dept_avg' => $deptMap[(int) $r['semester']] ?? (float) $r['student_sgpa'],
        ], $histRows);

        // 2. Submission Timeliness (real counts)
        $timeStmt = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN sm.id IS NOT NULL AND sm.is_late = 0 THEN 1 ELSE 0 END) AS on_time,
                SUM(CASE WHEN sm.id IS NOT NULL AND sm.is_late = 1 THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN sm.id IS NULL THEN 1 ELSE 0 END) AS pending
             FROM activities a
             LEFT JOIN submissions sm ON sm.activity_id = a.id AND sm.student_id = ?
             WHERE a.year = ? AND a.division = ?"
        );
        $timeStmt->execute([$stuId, $student['year'], $student['division']]);
        $tRow = $timeStmt->fetch() ?: [];
        $onTime = (int) ($tRow['on_time'] ?? 0);
        $late = (int) ($tRow['late'] ?? 0);
        $pending = (int) ($tRow['pending'] ?? 0);
        $totalAct = $onTime + $late + $pending;
        $timelyRate = $totalAct > 0 ? round(($onTime / $totalAct) * 100, 1) : 0.0;

        // 3. Subject-wise competency radar (latest published semester)
        $latestSem = !empty($semesters) ? end($semesters)['semester'] : (int) $student['semester'];
        $subStmt = $pdo->prepare(
            "SELECT sub.id, sub.code, sub.name,
                    ROUND((sm.total_marks / NULLIF(sm.max_marks, 0)) * 100, 1) AS student_pct,
                    ROUND((sm.in_sem / NULLIF(sub.max_in_sem, 0)) * 100, 1) AS insem_pct
             FROM semester_marks sm
             JOIN subjects sub ON sm.subject_id = sub.id
             JOIN exam_results_release er ON sm.exam_id = er.id
             WHERE sm.student_id = ? AND sm.semester = ? AND er.status IN ('published','locked')
             ORDER BY sub.code"
        );
        $subStmt->execute([$stuId, $latestSem]);
        $subRows = $subStmt->fetchAll();

        $benchStmt = $pdo->prepare(
            "SELECT sm.subject_id, ROUND(AVG((sm.total_marks / NULLIF(sm.max_marks, 0)) * 100), 1) AS class_pct
             FROM semester_marks sm
             JOIN exam_results_release er ON sm.exam_id = er.id
             WHERE sm.semester = ? AND er.status IN ('published','locked')
             GROUP BY sm.subject_id"
        );
        $benchStmt->execute([$latestSem]);
        $benchMap = [];
        foreach ($benchStmt->fetchAll() as $b) {
            $benchMap[(int) $b['subject_id']] = (float) $b['class_pct'];
        }

        $subjects = array_map(fn($r) => [
            'code' => $r['code'],
            'name' => $r['name'],
            'student_pct' => (float) $r['student_pct'],
            'class_pct' => $benchMap[(int) $r['id']] ?? 75.0,
        ], $subRows);

        // 4. Explainable Predictive Model
        $enoughData = count($semesters) >= 2;
        $prediction = [
            'enough_data' => $enoughData,
            'next_semester' => ((int) $student['semester']) + 1,
            'predicted_sgpa' => null,
            'margin' => null,
            'confidence' => null,
            'classification' => null,
            'factors' => [],
            'recommendations' => [],
        ];

        if ($enoughData) {
            $sgpas = array_column($semesters, 'student_sgpa');
            $n = count($sgpas);
            $meanSgpa = array_sum($sgpas) / $n;

            // Simple linear regression slope across semesters
            $xMean = ($n - 1) / 2.0;
            $num = 0.0;
            $den = 0.0;
            foreach ($sgpas as $i => $y) {
                $num += ($i - $xMean) * ($y - $meanSgpa);
                $den += ($i - $xMean) * ($i - $xMean);
            }
            $slope = $den > 0 ? ($num / $den) : 0.0;

            $avgInSemPct = !empty($subRows)
                ? array_sum(array_map(fn($r) => (float) $r['insem_pct'], $subRows)) / count($subRows)
                : ($meanSgpa * 10.0);

            $inSemScale10 = min(10.0, max(0.0, $avgInSemPct / 10.0));
            $timelyScale10 = min(10.0, max(0.0, ($totalAct > 0 ? ($onTime / $totalAct) : 0.8) * 10.0));

            $rawPred = (0.60 * $meanSgpa) + (0.25 * $inSemScale10) + (0.15 * $timelyScale10) + (0.25 * $slope);
            $predicted = round(min(10.0, max(0.0, $rawPred)), 2);

            // Standard deviation for margin
            $variance = array_sum(array_map(fn($y) => ($y - $meanSgpa) ** 2, $sgpas)) / $n;
            $margin = round(max(0.15, min(0.60, sqrt($variance) + 0.05)), 2);
            $confidence = round(min(96.0, max(65.0, 75.0 + ($n * 3.0) + ($totalAct > 0 ? 4.0 : 0.0))), 1);

            $classification = $predicted >= 7.75 ? 'First Class with Distinction' : ($predicted >= 6.75 ? 'First Class' : 'Second Class');

            $prediction = [
                'enough_data' => true,
                'next_semester' => ((int) $student['semester']) + 1,
                'predicted_sgpa' => $predicted,
                'margin' => $margin,
                'confidence' => $confidence,
                'classification' => $classification,
                'factors' => [
                    ['name' => 'Historical CGPA Baseline', 'weight' => 0.60, 'value' => round($meanSgpa, 2), 'contribution' => round(0.60 * $meanSgpa, 2)],
                    ['name' => 'In-Sem Continuous Score', 'weight' => 0.25, 'value' => round($avgInSemPct, 1) . '%', 'contribution' => round(0.25 * $inSemScale10, 2)],
                    ['name' => 'Timely Activity Rate', 'weight' => 0.15, 'value' => $timelyRate . '%', 'contribution' => round(0.15 * $timelyScale10, 2)],
                    ['name' => 'Semester Velocity Trend', 'weight' => 0.25, 'value' => ($slope >= 0 ? '+' : '') . round($slope, 3) . '/sem', 'contribution' => round(0.25 * $slope, 2)],
                ],
            ];

            // Build dynamic recommendations from strongest/weakest subjects
            if (!empty($subjects)) {
                $sorted = $subjects;
                usort($sorted, fn($a, $b) => $b['student_pct'] <=> $a['student_pct']);
                $best = $sorted[0];
                $worst = end($sorted);
                $prediction['recommendations'][] = [
                    'type' => 'strength',
                    'title' => "Core Strength: {$best['name']} ({$best['code']})",
                    'detail' => "You achieved {$best['student_pct']}% (class benchmark: {$best['class_pct']}%). Keep leveraging this strength for final-year electives.",
                ];
                if ($worst['student_pct'] < 75) {
                    $prediction['recommendations'][] = [
                        'type' => 'growth',
                        'title' => "Growth Area: {$worst['name']} ({$worst['code']})",
                        'detail' => "Scored {$worst['student_pct']}% compared to your overall average. Focus on past-paper practice and timely lab submissions.",
                    ];
                }
            }
        }

        Response::json([
            'semesters' => $semesters,
            'timeliness' => [
                'on_time' => $onTime,
                'late' => $late,
                'pending' => $pending,
                'timely_rate' => $timelyRate,
            ],
            'subjects' => $subjects,
            'prediction' => $prediction,
        ]);
    }

    public function facultyAnalytics(Request $req): void
    {
        $pdo = Database::getConnection();
        $uid = (int) Auth::userId();
        $fStmt = $pdo->prepare('SELECT id FROM faculty WHERE user_id = ? LIMIT 1');
        $fStmt->execute([$uid]);
        $facId = (int) $fStmt->fetchColumn();

        // Activity-wise average %
        $actStmt = $pdo->prepare(
            "SELECT a.title, sub.code AS subject_code, a.division,
                    ROUND(AVG((sm.marks / NULLIF(a.max_marks, 0)) * 100), 1) AS avg_pct,
                    COUNT(sm.id) AS graded_count
             FROM activities a
             JOIN subjects sub ON a.subject_id = sub.id
             LEFT JOIN submissions sm ON sm.activity_id = a.id AND sm.status = 'graded'
             WHERE a.faculty_id = ?
             GROUP BY a.id
             ORDER BY a.deadline ASC"
        );
        $actStmt->execute([$facId]);
        $activities = $actStmt->fetchAll();

        $sgpaThreshold = (float) Settings::get('at_risk_sgpa_threshold', '6.50');

        // At-risk students in faculty's allocated divisions
        $riskStmt = $pdo->prepare(
            "SELECT DISTINCT st.zprn, u.full_name, st.year, st.division, st.current_sgpa,
                    (SELECT COUNT(*) FROM submissions sm WHERE sm.student_id = st.id AND sm.is_late = 1) AS late_count
             FROM students st
             JOIN users u ON st.user_id = u.id
             JOIN faculty_allocations fa ON fa.year = st.year AND fa.division = st.division
             WHERE fa.faculty_id = ? AND fa.status = 'active'
               AND ((st.current_sgpa > 0 AND st.current_sgpa < ?) OR EXISTS (SELECT 1 FROM submissions sm WHERE sm.student_id = st.id AND sm.is_late = 1))
             ORDER BY st.current_sgpa ASC"
        );
        $riskStmt->execute([$facId, $sgpaThreshold]);

        Response::json([
            'activity_performance' => $activities,
            'at_risk_students' => $riskStmt->fetchAll(),
        ]);
    }

    public function adminAnalytics(Request $req): void
    {
        $pdo = Database::getConnection();
        $subPass = $pdo->query(
            "SELECT sub.code, sub.name,
                    ROUND(AVG((sm.total_marks / NULLIF(sm.max_marks, 0)) * 100), 1) AS avg_pct,
                    ROUND(AVG(sm.passed) * 100, 1) AS pass_rate,
                    COUNT(sm.id) AS total_records
             FROM semester_marks sm
             JOIN subjects sub ON sm.subject_id = sub.id
             JOIN exam_results_release er ON sm.exam_id = er.id
             WHERE er.status IN ('published','locked')
             GROUP BY sub.id
             ORDER BY sub.code"
        )->fetchAll();

        Response::json(['subject_summary' => $subPass]);
    }
}
