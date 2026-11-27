<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

class SppuGrading
{
    public static function computeSubjectGrade(int $inSem, int $endSem, int $practical, int $maxIn = 30, int $maxEnd = 70, int $maxPrac = 50): array
    {
        $maxTotal = $maxIn + $maxEnd + $maxPrac;
        $total = $inSem + $endSem + $practical;
        $pct = $maxTotal > 0 ? ($total / $maxTotal) * 100.0 : 0.0;

        if ($maxEnd > 0 && $endSem < (int) ceil($maxEnd * 0.40)) {
            return [$total, $maxTotal, 'F', 0.00, 0];
        }
        if ($pct >= 85) return [$total, $maxTotal, 'O', 10.00, 1];
        if ($pct >= 75) return [$total, $maxTotal, 'A+', 9.00, 1];
        if ($pct >= 65) return [$total, $maxTotal, 'A', 8.00, 1];
        if ($pct >= 55) return [$total, $maxTotal, 'B+', 7.00, 1];
        if ($pct >= 50) return [$total, $maxTotal, 'B', 6.00, 1];
        if ($pct >= 45) return [$total, $maxTotal, 'C', 5.00, 1];
        if ($pct >= 40) return [$total, $maxTotal, 'P', 4.00, 1];
        return [$total, $maxTotal, 'F', 0.00, 0];
    }

    public static function recalculateStudentAverages(PDO $pdo, int $studentId): void
    {
        $stmt = $pdo->prepare(
            'SELECT sm.semester, sm.grade_points, sub.credits
             FROM semester_marks sm
             JOIN subjects sub ON sm.subject_id = sub.id
             WHERE sm.student_id = ?'
        );
        $stmt->execute([$studentId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return;
        }

        $bySem = [];
        foreach ($rows as $r) {
            $sem = (int) $r['semester'];
            $cred = max(1, (int) $r['credits']);
            $bySem[$sem]['pts'] = ($bySem[$sem]['pts'] ?? 0.0) + ((float) $r['grade_points'] * $cred);
            $bySem[$sem]['cred'] = ($bySem[$sem]['cred'] ?? 0) + $cred;
        }

        $updSem = $pdo->prepare('UPDATE semester_marks SET sgpa = ? WHERE student_id = ? AND semester = ?');
        $totalPts = 0.0;
        $totalCred = 0;
        $latestSem = 0;
        $latestSgpa = 0.0;

        foreach ($bySem as $sem => $agg) {
            $sgpa = round($agg['pts'] / max(1, $agg['cred']), 2);
            $updSem->execute([$sgpa, $studentId, $sem]);
            $totalPts += $agg['pts'];
            $totalCred += $agg['cred'];
            if ($sem >= $latestSem) {
                $latestSem = $sem;
                $latestSgpa = $sgpa;
            }
        }

        $cgpa = round($totalPts / max(1, $totalCred), 2);
        $pdo->prepare('UPDATE students SET current_sgpa = ?, cgpa = ? WHERE id = ?')
            ->execute([$latestSgpa, $cgpa, $studentId]);
    }
}
