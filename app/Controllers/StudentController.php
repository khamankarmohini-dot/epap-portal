<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\FileStorage;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Validator;
use PDO;

class StudentController
{
    private const ALLOWED_EXTS = ['pdf', 'zip', 'cpp', 'c', 'java', 'py', 'txt', 'docx'];

    private function getStudent(PDO $pdo): array
    {
        $uid = (int) Auth::userId();
        $stmt = $pdo->prepare(
            'SELECT s.*, u.full_name, u.email, u.must_change_password
             FROM students s JOIN users u ON s.user_id = u.id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$uid]);
        $student = $stmt->fetch();
        if (!$student) {
            Response::error('Student profile not found.', 404);
        }
        return $student;
    }

    public function profile(Request $req): void
    {
        $pdo = Database::getConnection();
        $student = $this->getStudent($pdo);
        $student['must_change_password'] = (bool) $student['must_change_password'];
        Response::json($student);
    }

    public function stats(Request $req): void
    {
        $pdo = Database::getConnection();
        $student = $this->getStudent($pdo);

        $pStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM activities a
             LEFT JOIN submissions sm ON sm.activity_id = a.id AND sm.student_id = ?
             WHERE a.division = ? AND a.year = ? AND sm.id IS NULL AND (a.deadline > NOW() OR a.allow_late = 1)'
        );
        $pStmt->execute([(int) $student['id'], $student['division'], $student['year']]);
        $pending = (int) $pStmt->fetchColumn();

        $gStmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE student_id = ? AND status = 'graded'");
        $gStmt->execute([(int) $student['id']]);
        $graded = (int) $gStmt->fetchColumn();

        $cStmt = $pdo->prepare("SELECT COUNT(DISTINCT problem_id) FROM practice_submissions WHERE student_id = ? AND verdict = 'Accepted'");
        $cStmt->execute([(int) $student['id']]);
        $codingSolved = (int) $cStmt->fetchColumn();

        $tStmt = $pdo->query('SELECT COUNT(*) FROM practice_problems');
        $codingTotal = (int) $tStmt->fetchColumn();

        Response::json([
            'sgpa' => (float) $student['current_sgpa'],
            'cgpa' => (float) $student['cgpa'],
            'pending_activities' => $pending,
            'graded_submissions' => $graded,
            'coding_solved' => $codingSolved,
            'coding_total' => $codingTotal,
        ]);
    }

    public function activities(Request $req): void
    {
        $pdo = Database::getConnection();
        $student = $this->getStudent($pdo);

        $stmt = $pdo->prepare(
            'SELECT a.*, sub.code AS subject_code, sub.name AS subject_name,
                    u.full_name AS faculty_name,
                    sm.id AS submission_id, sm.marks, sm.feedback, sm.status AS sub_status,
                    sm.is_late, sm.submitted_at, sm.file_name, sm.original_name, sm.project_url
             FROM activities a
             JOIN subjects sub ON a.subject_id = sub.id
             JOIN faculty f ON a.faculty_id = f.id
             JOIN users u ON f.user_id = u.id
             LEFT JOIN submissions sm ON sm.activity_id = a.id AND sm.student_id = ?
             WHERE a.division = ? AND a.year = ?
             ORDER BY a.deadline ASC'
        );
        $stmt->execute([(int) $student['id'], $student['division'], $student['year']]);
        Response::json($stmt->fetchAll());
    }

    public function submit(Request $req): void
    {
        $pdo = Database::getConnection();
        $student = $this->getStudent($pdo);

        $body = $req->body();
        $data = Validator::validate($body, [
            'activity_id' => 'required|int',
            'project_url' => 'max:255',
            'notes'       => 'max:2000',
        ]);
        $activityId = (int) $data['activity_id'];

        $aStmt = $pdo->prepare('SELECT * FROM activities WHERE id = ? LIMIT 1');
        $aStmt->execute([$activityId]);
        $activity = $aStmt->fetch();
        if (!$activity) {
            Response::error('Activity not found.', 404);
        }

        if ($activity['division'] !== $student['division'] || $activity['year'] !== $student['year']) {
            Logger::audit($pdo, Auth::userId(), 'UNAUTHORIZED_SUBMISSION', "Attempted activity {$activityId}", 'FAILURE');
            Response::error('Forbidden: this activity does not belong to your class/division.', 403);
        }

        // Check if already graded
        $chkStmt = $pdo->prepare('SELECT id, status FROM submissions WHERE activity_id = ? AND student_id = ?');
        $chkStmt->execute([$activityId, (int) $student['id']]);
        $existing = $chkStmt->fetch();
        if ($existing && $existing['status'] === 'graded') {
            Response::error('This submission has already been graded and cannot be modified.', 409);
        }

        $isLate = (time() > strtotime($activity['deadline'])) ? 1 : 0;
        if ($isLate && !(bool) $activity['allow_late']) {
            Response::error('Deadline has passed and late submissions are disabled for this activity.', 422);
        }

        // Handle real multipart file upload
        $file = $req->file('file');
        $origName = null;
        $ext = '';
        $fileSize = 0;
        $mimeType = '';

        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                Response::error('File upload failed (error code ' . $file['error'] . ').', 400);
            }
            $maxMb = (int) Settings::get('upload_max_mb', (string) Env::get('MAX_UPLOAD_MB', '10'));
            $maxBytes = max(1, $maxMb) * 1024 * 1024;
            if ((int) $file['size'] > $maxBytes) {
                Response::error("File exceeds maximum allowed size ({$maxMb} MB).", 422);
            }
            $origName = basename((string) $file['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTS, true)) {
                Response::error('Unsupported file type. Allowed: ' . implode(', ', self::ALLOWED_EXTS), 422);
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = (string) $finfo->file($file['tmp_name']);
            if (str_contains($mimeType, 'php') || str_contains($mimeType, 'x-executable')) {
                Response::error('Executable or script files are not permitted.', 422);
            }
            $fileSize = (int) $file['size'];
        } else {
            Response::error('Please attach a solution file to submit.', 422);
        }

        $projectUrl = trim((string) ($data['project_url'] ?? ''));
        if ($projectUrl !== '' && !filter_var($projectUrl, FILTER_VALIDATE_URL)) {
            Response::error('Project URL must be a valid URL.', 422);
        }
        $notes = trim((string) ($data['notes'] ?? ''));

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO submissions (activity_id, student_id, file_name, original_name, file_path, file_size, mime_type, project_url, notes, is_late)
                 VALUES (?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   file_name = VALUES(file_name),
                   original_name = VALUES(original_name),
                   file_size = VALUES(file_size),
                   mime_type = VALUES(mime_type),
                   project_url = VALUES(project_url),
                   notes = VALUES(notes),
                   submitted_at = NOW(),
                   is_late = VALUES(is_late)'
            )->execute([
                $activityId,
                (int) $student['id'],
                $origName,
                $origName,
                '',
                $fileSize,
                $mimeType,
                $projectUrl ?: null,
                $notes ?: null,
                $isLate,
            ]);

            $idStmt = $pdo->prepare('SELECT id FROM submissions WHERE activity_id = ? AND student_id = ? LIMIT 1');
            $idStmt->execute([$activityId, (int) $student['id']]);
            $subId = (int) $idStmt->fetchColumn();

            $relPath = FileStorage::save($pdo, $subId, (string) $file['tmp_name'], (int) $student['id'], $origName, $ext, $mimeType, $fileSize);
            $pdo->prepare('UPDATE submissions SET file_path = ? WHERE id = ?')->execute([$relPath, $subId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error($e->getMessage(), 500);
        }

        Logger::audit($pdo, Auth::userId(), 'SUBMISSION_CREATED', "Activity {$activityId} ({$origName}), late={$isLate}");
        Response::json([
            'success' => true,
            'is_late' => (bool) $isLate,
            'file_name' => $origName,
        ]);
    }

    public function downloadSubmission(Request $req, array $params): void
    {
        $pdo = Database::getConnection();
        $student = $this->getStudent($pdo);
        $subId = (int) ($params['id'] ?? 0);

        $stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = ? AND student_id = ? LIMIT 1');
        $stmt->execute([$subId, (int) $student['id']]);
        $sub = $stmt->fetch();
        if (!$sub || empty($sub['file_path'])) {
            Response::error('Submission file not found.', 404);
        }

        Logger::audit($pdo, Auth::userId(), 'SUBMISSION_DOWNLOADED', "Submission {$subId}");
        FileStorage::stream($pdo, $sub);
    }

    public function results(Request $req): void
    {
        $pdo = Database::getConnection();
        $student = $this->getStudent($pdo);
        $semFilter = $req->query('semester');

        $sql = "SELECT sm.*, sub.code, sub.name, sub.credits, sub.max_in_sem, sub.max_end_sem, sub.max_practical,
                       er.exam_name, er.status AS release_status, er.published_at
                FROM semester_marks sm
                JOIN subjects sub ON sm.subject_id = sub.id
                JOIN exam_results_release er ON sm.exam_id = er.id
                WHERE sm.student_id = ? AND er.status IN ('published','locked')";
        $bind = [(int) $student['id']];

        if ($semFilter !== null && $semFilter !== '') {
            $sql .= ' AND sm.semester = ?';
            $bind[] = (int) $semFilter;
        }
        $sql .= ' ORDER BY sm.semester DESC, sub.code ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $marks = $stmt->fetchAll();

        Response::json($marks);
    }

    public function notifications(Request $req): void
    {
        $pdo = Database::getConnection();
        $uid = (int) Auth::userId();
        $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15');
        $stmt->execute([$uid]);
        $items = $stmt->fetchAll();
        $unread = count(array_filter($items, fn($n) => !(bool) $n['is_read']));
        Response::json(['items' => $items, 'unread' => $unread]);
    }

    public function markNotificationsRead(Request $req): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([(int) Auth::userId()]);
        Response::json(['success' => true]);
    }

    public function summary(Request $req): void
    {
        $pdo     = Database::getConnection();
        $student = $this->getStudent($pdo);
        $sid     = (int) $student['id'];

        // --- Pending: not submitted AND (deadline > NOW() OR allow_late=1) ---
        $pStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM activities a
             LEFT JOIN submissions sm ON sm.activity_id = a.id AND sm.student_id = ?
             WHERE a.division = ? AND a.year = ? AND sm.id IS NULL
               AND (a.deadline > NOW() OR a.allow_late = 1)'
        );
        $pStmt->execute([$sid, $student['division'], $student['year']]);
        $pending = (int) $pStmt->fetchColumn();

        // --- Coding stats ---
        $cStmt = $pdo->prepare("SELECT COUNT(DISTINCT problem_id) FROM practice_submissions WHERE student_id = ? AND verdict = 'Accepted'");
        $cStmt->execute([$sid]);
        $codingSolved = (int) $cStmt->fetchColumn();

        $tStmt = $pdo->query('SELECT COUNT(*) FROM practice_problems');
        $codingTotal = (int) $tStmt->fetchColumn();

        // --- Unread notifications ---
        $nStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $nStmt->execute([(int) Auth::userId()]);
        $unread = (int) $nStmt->fetchColumn();

        // --- Settings ---
        $settings = Settings::publicSettings($pdo);

        // --- Initials ---
        $words    = preg_split('/\s+/', trim($student['full_name']), -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';
        if (count($words) >= 2) {
            $initials = strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1));
        } elseif (count($words) === 1) {
            $initials = strtoupper(mb_substr($words[0], 0, 2));
        }

        // --- Year/semester consistency ---
        $validSems = [
            'FE-IT' => [1, 2],
            'SE-IT' => [3, 4],
            'TE-IT' => [5, 6],
            'BE-IT' => [7, 8],
        ];
        $yearKey   = $student['year'] ?? '';
        $semInt    = (int) $student['semester'];
        $consistent = isset($validSems[$yearKey]) && in_array($semInt, $validSems[$yearKey], true);

        Response::json([
            'pending_activities'   => $pending,
            'due_activities'       => $pending,
            'coding_solved'        => $codingSolved,
            'coding_total'         => $codingTotal,
            'unread_notifications' => $unread,
            'sgpa'                 => (float) $student['current_sgpa'],
            'cgpa'                 => (float) $student['cgpa'],
            'user' => [
                'full_name'  => $student['full_name'],
                'identifier' => $student['zprn'],
                'initials'   => $initials,
            ],
            'student' => [
                'year'       => $student['year'],
                'division'   => $student['division'],
                'semester'   => $semInt,
                'roll_no'    => (int) $student['roll_no'],
                'zprn'       => $student['zprn'],
                'consistent' => $consistent,
            ],
            'session' => [
                'academic_year'    => $settings['academic_year'],
                'current_semester' => $settings['current_semester'],
            ],
            'institution_name' => $settings['institution_name'],
            'department_name'  => $settings['department_name'],
            'benchmark_label'  => $settings['benchmark_label'],
            'curriculum_label' => $settings['curriculum_label'],
            'server_now'       => date('c'),
        ]);
    }
}
