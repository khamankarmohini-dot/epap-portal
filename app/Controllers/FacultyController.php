<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\FileStorage;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use PDO;

class FacultyController
{
    private function getFaculty(PDO $pdo): array
    {
        $uid = (int) Auth::userId();
        $stmt = $pdo->prepare(
            'SELECT f.*, u.full_name, u.email, u.must_change_password
             FROM faculty f JOIN users u ON f.user_id = u.id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$uid]);
        $fac = $stmt->fetch();
        if (!$fac) {
            Response::error('Faculty profile not found.', 404);
        }
        return $fac;
    }

    public function profile(Request $req): void
    {
        $pdo = Database::getConnection();
        $fac = $this->getFaculty($pdo);
        $aStmt = $pdo->prepare(
            "SELECT fa.*, s.code AS subject_code, s.name AS subject_name
             FROM faculty_allocations fa
             JOIN subjects s ON fa.subject_id = s.id
             WHERE fa.faculty_id = ? AND fa.status = 'active'"
        );
        $aStmt->execute([(int) $fac['id']]);
        $fac['allocations'] = $aStmt->fetchAll();
        Response::json($fac);
    }

    public function allocations(Request $req): void
    {
        $pdo = Database::getConnection();
        $fac = $this->getFaculty($pdo);
        $stmt = $pdo->prepare(
            "SELECT fa.*, s.code AS subject_code, s.name AS subject_name,
                    (SELECT COUNT(*) FROM students st WHERE st.year = fa.year AND st.division = fa.division) AS student_count
             FROM faculty_allocations fa
             JOIN subjects s ON fa.subject_id = s.id
             WHERE fa.faculty_id = ? AND fa.status = 'active'
             ORDER BY fa.year, fa.division, s.code"
        );
        $stmt->execute([(int) $fac['id']]);
        Response::json($stmt->fetchAll());
    }

    public function stats(Request $req): void
    {
        $pdo = Database::getConnection();
        $fac = $this->getFaculty($pdo);
        $fid = (int) $fac['id'];

        $stuStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT st.id)
             FROM students st
             JOIN faculty_allocations fa ON st.year = fa.year AND st.division = fa.division
             WHERE fa.faculty_id = ? AND fa.status = 'active'"
        );
        $stuStmt->execute([$fid]);
        $totalStudents = (int) $stuStmt->fetchColumn();

        $pendStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM submissions sm
             JOIN activities a ON sm.activity_id = a.id
             WHERE a.faculty_id = ? AND sm.status = 'submitted'"
        );
        $pendStmt->execute([$fid]);
        $pending = (int) $pendStmt->fetchColumn();

        $gradStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM submissions sm
             JOIN activities a ON sm.activity_id = a.id
             WHERE a.faculty_id = ? AND sm.status = 'graded'"
        );
        $gradStmt->execute([$fid]);
        $graded = (int) $gradStmt->fetchColumn();

        $avgStmt = $pdo->prepare(
            "SELECT ROUND(AVG((sm.marks / NULLIF(a.max_marks, 0)) * 100), 1)
             FROM submissions sm
             JOIN activities a ON sm.activity_id = a.id
             WHERE a.faculty_id = ? AND sm.status = 'graded'"
        );
        $avgStmt->execute([$fid]);
        $avgVal = $avgStmt->fetchColumn();
        $classAvg = $avgVal !== null ? (float) $avgVal : 0.0;

        Response::json([
            'total_students' => $totalStudents,
            'pending_evaluations' => $pending,
            'graded_submissions' => $graded,
            'class_avg' => $classAvg,
        ]);
    }

    public function submissions(Request $req): void
    {
        $pdo = Database::getConnection();
        $fac = $this->getFaculty($pdo);

        $sql = 'SELECT sm.id, sm.marks, sm.feedback, sm.status, sm.is_late, sm.submitted_at, sm.file_name, sm.original_name, sm.file_path, sm.notes, sm.project_url,
                       u.full_name AS student_name, s.zprn, s.division, s.year,
                       a.id AS activity_id, a.title AS activity_title, a.max_marks, a.deadline,
                       sub.code AS subject_code
                FROM submissions sm
                JOIN students s ON sm.student_id = s.id
                JOIN users u ON s.user_id = u.id
                JOIN activities a ON sm.activity_id = a.id
                JOIN subjects sub ON a.subject_id = sub.id
                WHERE a.faculty_id = ?';
        $bind = [(int) $fac['id']];

        if ($req->query('activity_id')) {
            $sql .= ' AND a.id = ?';
            $bind[] = (int) $req->query('activity_id');
        }
        if ($req->query('status')) {
            $sql .= ' AND sm.status = ?';
            $bind[] = (string) $req->query('status');
        }
        if ($req->query('is_late') !== null && $req->query('is_late') !== '') {
            $sql .= ' AND sm.is_late = ?';
            $bind[] = (int) $req->query('is_late');
        }

        $sql .= ' ORDER BY sm.status ASC, sm.submitted_at DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        Response::json($stmt->fetchAll());
    }

    public function grade(Request $req): void
    {
        $pdo = Database::getConnection();
        $fac = $this->getFaculty($pdo);

        $data = Validator::validate($req->body(), [
            'submission_id' => 'required|int',
            'marks'         => 'required|int|min:0|max:100',
            'feedback'      => 'required|min:2|max:2000',
        ]);

        $subId = (int) $data['submission_id'];
        $marks = (int) $data['marks'];
        $feedback = trim((string) $data['feedback']);

        $stmt = $pdo->prepare(
            'SELECT sm.id, sm.student_id, a.faculty_id, a.max_marks, a.title, st.user_id AS student_user_id
             FROM submissions sm
             JOIN activities a ON sm.activity_id = a.id
             JOIN students st ON sm.student_id = st.id
             WHERE sm.id = ? LIMIT 1'
        );
        $stmt->execute([$subId]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('Submission not found.', 404);
        }
        if ((int) $row['faculty_id'] !== (int) $fac['id']) {
            Logger::audit($pdo, Auth::userId(), 'UNAUTHORIZED_GRADE', "Attempted submission {$subId}", 'FAILURE');
            Response::error('Forbidden: you are not assigned to this activity.', 403);
        }
        if ($marks < 0 || $marks > (int) $row['max_marks']) {
            Response::error("Marks must be between 0 and {$row['max_marks']}.", 422);
        }

        $pdo->prepare("UPDATE submissions SET marks = ?, feedback = ?, status = 'graded', graded_at = NOW() WHERE id = ?")
            ->execute([$marks, $feedback, $subId]);

        $pdo->prepare('INSERT INTO notifications (user_id, title, message, link) VALUES (?,?,?,?)')
            ->execute([
                (int) $row['student_user_id'],
                'Activity Graded: ' . mb_substr($row['title'], 0, 80),
                "You scored {$marks}/{$row['max_marks']}. Feedback: " . mb_substr($feedback, 0, 120),
                '/student-activities.html',
            ]);

        Logger::audit($pdo, Auth::userId(), 'SUBMISSION_GRADED', "Submission {$subId}: {$marks}/{$row['max_marks']}");
        Response::json(['success' => true]);
    }

    public function activities(Request $req): void
    {
        $pdo = Database::getConnection();
        $fac = $this->getFaculty($pdo);

        $stmt = $pdo->prepare(
            "SELECT a.*, sub.code AS subject_code, sub.name AS subject_name,
                    COUNT(sm.id) AS submission_count,
                    SUM(CASE WHEN sm.status = 'graded' THEN 1 ELSE 0 END) AS graded_count,
                    (SELECT COUNT(*) FROM students st WHERE st.year = a.year AND st.division = a.division) AS total_students
             FROM activities a
             JOIN subjects sub ON a.subject_id = sub.id
             LEFT JOIN submissions sm ON sm.activity_id = a.id
             WHERE a.faculty_id = ?
             GROUP BY a.id
             ORDER BY a.deadline DESC"
        );
        $stmt->execute([(int) $fac['id']]);
        Response::json($stmt->fetchAll());
    }

    public function createActivity(Request $req): void
    {
        $pdo = Database::getConnection();
        $fac = $this->getFaculty($pdo);

        $data = Validator::validate($req->body(), [
            'subject_id'    => 'required|int',
            'title'         => 'required|min:3|max:200',
            'description'   => 'max:3000',
            'max_marks'     => 'int|min:1|max:100',
            'deadline'      => 'required',
            'division'      => 'required|in:A,B,C',
            'year'          => 'in:SE-IT,TE-IT,BE-IT',
            'activity_type' => 'max:60',
        ]);

        $subjectId = (int) $data['subject_id'];
        $division = (string) $data['division'];
        $year = (string) ($data['year'] ?? 'TE-IT');
        $deadlineTs = strtotime((string) $data['deadline']);
        if ($deadlineTs === false || $deadlineTs <= time()) {
            Response::error('Deadline must be a valid future date and time.', 422);
        }

        // Verify faculty is allocated to this subject + division + year
        $allocStmt = $pdo->prepare(
            "SELECT id FROM faculty_allocations
             WHERE faculty_id = ? AND subject_id = ? AND division = ? AND year = ? AND status = 'active' LIMIT 1"
        );
        $allocStmt->execute([(int) $fac['id'], $subjectId, $division, $year]);
        if (!$allocStmt->fetch()) {
            Response::error('Forbidden: you are not allocated to teach this subject for ' . $year . ' Div ' . $division . '.', 403);
        }

        $maxMarks = (int) ($data['max_marks'] ?? 25);
        $title = trim((string) $data['title']);
        $desc = trim((string) ($data['description'] ?? ''));
        $type = trim((string) ($data['activity_type'] ?? 'Lab Practical Journal'));

        $pdo->prepare(
            'INSERT INTO activities (faculty_id, subject_id, title, activity_type, description, max_marks, deadline, year, division)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            (int) $fac['id'],
            $subjectId,
            $title,
            $type,
            $desc,
            $maxMarks,
            date('Y-m-d H:i:s', $deadlineTs),
            $year,
            $division,
        ]);
        $newId = (int) $pdo->lastInsertId();

        Logger::audit($pdo, Auth::userId(), 'ACTIVITY_CREATED', "Activity {$newId}: {$title} ({$year} Div {$division})");
        Response::json(['success' => true, 'id' => $newId], 201);
    }

    public function deleteActivity(Request $req, array $params): void
    {
        $pdo = Database::getConnection();
        $fac = $this->getFaculty($pdo);
        $actId = (int) ($params['id'] ?? 0);

        $stmt = $pdo->prepare('SELECT faculty_id, title FROM activities WHERE id = ?');
        $stmt->execute([$actId]);
        $act = $stmt->fetch();
        if (!$act) {
            Response::error('Activity not found.', 404);
        }
        if ((int) $act['faculty_id'] !== (int) $fac['id']) {
            Response::error('Forbidden.', 403);
        }

        $pdo->prepare('DELETE FROM activities WHERE id = ?')->execute([$actId]);
        Logger::audit($pdo, Auth::userId(), 'ACTIVITY_DELETED', "Activity {$actId}: {$act['title']}");
        Response::json(['success' => true]);
    }

    public function downloadSubmission(Request $req, array $params): void
    {
        $pdo = Database::getConnection();
        $fac = $this->getFaculty($pdo);
        $subId = (int) ($params['id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT sm.* FROM submissions sm
             JOIN activities a ON sm.activity_id = a.id
             WHERE sm.id = ? AND a.faculty_id = ? LIMIT 1'
        );
        $stmt->execute([$subId, (int) $fac['id']]);
        $sub = $stmt->fetch();
        if (!$sub || empty($sub['file_path'])) {
            Response::error('Submission file not found.', 404);
        }

        Logger::audit($pdo, Auth::userId(), 'SUBMISSION_DOWNLOADED', "Faculty downloaded submission {$subId}");
        FileStorage::stream($pdo, $sub);
    }

    public function summary(Request $req): void
    {
        $pdo = Database::getConnection();
        $fac = $this->getFaculty($pdo);
        $fid = (int) $fac['id'];

        // Pending evaluations
        $pendStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM submissions sm
             JOIN activities a ON sm.activity_id = a.id
             WHERE a.faculty_id = ? AND sm.status = 'submitted'"
        );
        $pendStmt->execute([$fid]);
        $pending = (int) $pendStmt->fetchColumn();

        // Graded
        $gradStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM submissions sm
             JOIN activities a ON sm.activity_id = a.id
             WHERE a.faculty_id = ? AND sm.status = 'graded'"
        );
        $gradStmt->execute([$fid]);
        $graded = (int) $gradStmt->fetchColumn();

        // Total students
        $stuStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT st.id)
             FROM students st
             JOIN faculty_allocations fa ON st.year = fa.year AND st.division = fa.division
             WHERE fa.faculty_id = ? AND fa.status = 'active'"
        );
        $stuStmt->execute([$fid]);
        $totalStudents = (int) $stuStmt->fetchColumn();

        // Class avg
        $avgStmt = $pdo->prepare(
            "SELECT ROUND(AVG((sm.marks / NULLIF(a.max_marks, 0)) * 100), 1)
             FROM submissions sm
             JOIN activities a ON sm.activity_id = a.id
             WHERE a.faculty_id = ? AND sm.status = 'graded'"
        );
        $avgStmt->execute([$fid]);
        $avgVal   = $avgStmt->fetchColumn();
        $classAvg = $avgVal !== null ? (float) $avgVal : 0.0;

        // Allocations
        $aStmt = $pdo->prepare(
            "SELECT fa.year, fa.division, s.code AS subject_code, s.name AS subject_name
             FROM faculty_allocations fa
             JOIN subjects s ON fa.subject_id = s.id
             WHERE fa.faculty_id = ? AND fa.status = 'active'
             ORDER BY fa.year, fa.division, s.code"
        );
        $aStmt->execute([$fid]);
        $allocations = $aStmt->fetchAll();

        // Build class badge labels
        $classBadges = [];
        foreach ($allocations as $a) {
            $lbl = "{$a['year']} Div {$a['division']}";
            if (!in_array($lbl, $classBadges, true)) {
                $classBadges[] = $lbl;
            }
        }

        // Initials
        $words    = preg_split('/\s+/', trim($fac['full_name']), -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';
        if (count($words) >= 2) {
            $initials = strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1));
        } elseif (count($words) === 1) {
            $initials = strtoupper(mb_substr($words[0], 0, 2));
        }

        Response::json([
            'pending_evaluations' => $pending,
            'graded_submissions'  => $graded,
            'total_students'      => $totalStudents,
            'class_avg'           => $classAvg,
            'class_badges'        => $classBadges,
            'allocations'         => $allocations,
            'user' => [
                'full_name'   => $fac['full_name'],
                'identifier'  => $fac['employee_id'] ?? '',
                'initials'    => $initials,
                'designation' => $fac['designation'] ?? 'Faculty',
            ],
            'server_now' => date('c'),
        ]);
    }

    public function exportGradesCsv(Request $req): void
    {
        $pdo = Database::getConnection();
        $fac = $this->getFaculty($pdo);

        $stmt = $pdo->prepare(
            'SELECT s.zprn, u.full_name, s.year, s.division, sub.code AS subject_code,
                    a.title AS activity, sm.marks, a.max_marks, sm.status, sm.is_late, sm.submitted_at, sm.feedback
             FROM submissions sm
             JOIN students s ON sm.student_id = s.id
             JOIN users u ON s.user_id = u.id
             JOIN activities a ON sm.activity_id = a.id
             JOIN subjects sub ON a.subject_id = sub.id
             WHERE a.faculty_id = ?
             ORDER BY a.id, s.roll_no'
        );
        $stmt->execute([(int) $fac['id']]);
        $rows = $stmt->fetchAll();

        Logger::audit($pdo, Auth::userId(), 'GRADES_EXPORTED', 'Faculty exported grades CSV');
        Response::csv(
            'faculty_grades_' . date('Ymd') . '.csv',
            ['ZPRN', 'Student Name', 'Year', 'Division', 'Subject', 'Activity', 'Marks', 'Max Marks', 'Status', 'Late', 'Submitted At', 'Feedback'],
            array_map(fn($r) => array_values($r), $rows)
        );
    }
}
