<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\SppuGrading;
use App\Core\Validator;

class AdminController
{
    private const YEAR_SEMESTERS = [
        'FE-IT' => [1, 2],
        'SE-IT' => [3, 4],
        'TE-IT' => [5, 6],
        'BE-IT' => [7, 8],
    ];

    private static function isYearSemesterConsistent(string $year, int $semester): bool
    {
        return isset(self::YEAR_SEMESTERS[$year]) && in_array($semester, self::YEAR_SEMESTERS[$year], true);
    }

    public function summary(Request $req): void
    {
        $pdo = Database::getConnection();
        $students = (int) $pdo->query('SELECT COUNT(*) FROM students s JOIN users u ON s.user_id=u.id WHERE u.is_active=1')->fetchColumn();
        $faculty  = (int) $pdo->query('SELECT COUNT(*) FROM faculty f JOIN users u ON f.user_id=u.id WHERE u.is_active=1')->fetchColumn();
        $subjects = (int) $pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn();
        $subs     = (int) $pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn();
        $pending  = (int) $pdo->query("SELECT COUNT(*) FROM submissions WHERE status='submitted'")->fetchColumn();
        $passRate = $pdo->query(
            "SELECT ROUND(AVG(sm.passed) * 100, 1)
             FROM semester_marks sm
             JOIN exam_results_release er ON sm.exam_id = er.id
             WHERE er.status IN ('published','locked')"
        )->fetchColumn();

        $inconsistent = (int) $pdo->query(
            "SELECT COUNT(*) FROM students
             WHERE NOT (
                (year = 'FE-IT' AND semester IN (1, 2)) OR
                (year = 'SE-IT' AND semester IN (3, 4)) OR
                (year = 'TE-IT' AND semester IN (5, 6)) OR
                (year = 'BE-IT' AND semester IN (7, 8))
             )"
        )->fetchColumn();

        $uid = (int) Auth::userId();
        $uStmt = $pdo->prepare('SELECT full_name, identifier FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$uid]);
        $userRow = $uStmt->fetch() ?: ['full_name' => 'Administrator', 'identifier' => 'ADMIN'];

        $words = preg_split('/\s+/', trim((string) $userRow['full_name']), -1, PREG_SPLIT_NO_EMPTY);
        $initials = count($words) >= 2
            ? strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1))
            : strtoupper(mb_substr($words[0] ?? 'A', 0, 2));

        $settings = Settings::publicSettings($pdo);

        Response::json([
            'total_students'        => $students,
            'total_faculty'         => $faculty,
            'total_subjects'        => $subjects,
            'total_submissions'     => $subs,
            'pending_grading'       => $pending,
            'pass_rate'             => $passRate !== null ? (float) $passRate : 0.0,
            'inconsistent_students' => $inconsistent,
            'user' => [
                'full_name'  => $userRow['full_name'],
                'identifier' => $userRow['identifier'],
                'initials'   => $initials,
            ],
            'session' => [
                'academic_year'    => $settings['academic_year'],
                'current_semester' => $settings['current_semester'],
            ],
            'institution_name' => $settings['institution_name'],
            'department_name'  => $settings['department_name'],
            'server_now'       => date('c'),
        ]);
    }

    public function inconsistentStudents(Request $req): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            "SELECT s.id, s.zprn, s.year, s.division, s.semester, s.roll_no, u.full_name, u.email
             FROM students s
             JOIN users u ON s.user_id = u.id
             WHERE NOT (
                (s.year = 'FE-IT' AND s.semester IN (1, 2)) OR
                (s.year = 'SE-IT' AND s.semester IN (3, 4)) OR
                (s.year = 'TE-IT' AND s.semester IN (5, 6)) OR
                (s.year = 'BE-IT' AND s.semester IN (7, 8))
             )
             ORDER BY s.year, s.division, s.roll_no"
        );
        Response::json($stmt->fetchAll());
    }

    public function fixStudentYearSemester(Request $req, array $params): void
    {
        $studentId = (int) ($params['id'] ?? 0);
        $data = Validator::validate($req->body(), [
            'year'     => 'required|in:FE-IT,SE-IT,TE-IT,BE-IT',
            'semester' => 'required|int|min:1|max:8',
        ]);
        $year = (string) $data['year'];
        $semester = (int) $data['semester'];

        if (!self::isYearSemesterConsistent($year, $semester)) {
            Response::error("Semester {$semester} does not match year {$year} (FE-IT: 1-2, SE-IT: 3-4, TE-IT: 5-6, BE-IT: 7-8).", 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT s.id, s.zprn, s.year, s.semester FROM students s WHERE s.id = ? LIMIT 1');
        $stmt->execute([$studentId]);
        $stu = $stmt->fetch();
        if (!$stu) {
            Response::error('Student not found.', 404);
        }

        $pdo->prepare('UPDATE students SET year = ?, semester = ? WHERE id = ?')->execute([$year, $semester, $studentId]);
        Logger::audit($pdo, Auth::userId(), 'STUDENT_YEAR_SEM_FIXED', "{$stu['zprn']}: {$stu['year']}/Sem{$stu['semester']} -> {$year}/Sem{$semester}");
        Response::json(['success' => true, 'id' => $studentId, 'year' => $year, 'semester' => $semester]);
    }

    public function stats(Request $req): void
    {
        $pdo = Database::getConnection();
        $students = (int) $pdo->query('SELECT COUNT(*) FROM students s JOIN users u ON s.user_id=u.id WHERE u.is_active=1')->fetchColumn();
        $faculty  = (int) $pdo->query('SELECT COUNT(*) FROM faculty f JOIN users u ON f.user_id=u.id WHERE u.is_active=1')->fetchColumn();
        $subjects = (int) $pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn();
        $subs     = (int) $pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn();
        $pending  = (int) $pdo->query("SELECT COUNT(*) FROM submissions WHERE status='submitted'")->fetchColumn();
        $passRate = $pdo->query(
            "SELECT ROUND(AVG(sm.passed) * 100, 1)
             FROM semester_marks sm
             JOIN exam_results_release er ON sm.exam_id = er.id
             WHERE er.status IN ('published','locked')"
        )->fetchColumn();

        Response::json([
            'total_students' => $students,
            'total_faculty' => $faculty,
            'total_subjects' => $subjects,
            'total_submissions' => $subs,
            'pending_grading' => $pending,
            'pass_rate' => $passRate !== null ? (float) $passRate : 0.0,
        ]);
    }

    public function students(Request $req): void
    {
        $pdo = Database::getConnection();
        $q = trim((string) ($req->query('q', '')));
        $sql = 'SELECT s.*, u.full_name, u.email, u.is_active
                FROM students s JOIN users u ON s.user_id = u.id WHERE 1=1';
        $bind = [];
        if ($q !== '') {
            $sql .= ' AND (s.zprn LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
            $like = "%{$q}%";
            $bind = [$like, $like, $like];
        }
        $sql .= ' ORDER BY s.year, s.division, s.roll_no LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        Response::json($stmt->fetchAll());
    }

    public function createStudent(Request $req): void
    {
        $data = Validator::validate($req->body(), [
            'full_name' => 'required|min:2|max:100',
            'email'     => 'required|email|max:120',
            'zprn'      => 'required|min:5|max:60',
            'roll_no'   => 'int|min:0|max:999',
            'division'  => 'in:A,B,C',
            'year'      => 'in:FE-IT,SE-IT,TE-IT,BE-IT',
            'semester'  => 'int|min:1|max:8',
        ]);

        $year = (string) ($data['year'] ?? 'TE-IT');
        $semester = isset($data['semester']) ? (int) $data['semester'] : self::YEAR_SEMESTERS[$year][0];
        if (!self::isYearSemesterConsistent($year, $semester)) {
            Response::error("Semester {$semester} does not match year {$year} (FE-IT: 1-2, SE-IT: 3-4, TE-IT: 5-6, BE-IT: 7-8).", 422);
        }

        $pdo = Database::getConnection();
        $zprn = trim((string) $data['zprn']);
        $email = strtolower(trim((string) $data['email']));

        $dup = $pdo->prepare('SELECT id FROM users WHERE identifier = ? OR email = ? LIMIT 1');
        $dup->execute([$zprn, $email]);
        if ($dup->fetch()) {
            Response::error('A user with this ID or email address already exists.', 409);
        }

        $tempPassword = self::generateTempPassword();

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO users (identifier, password_hash, role, full_name, email, must_change_password) VALUES (?,?,?,?,?,1)'
            )->execute([$zprn, password_hash($tempPassword, PASSWORD_BCRYPT), 'student', trim((string) $data['full_name']), $email]);
            $uid = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO students (user_id, zprn, roll_no, division, year, semester) VALUES (?,?,?,?,?,?)'
            )->execute([
                $uid,
                $zprn,
                (int) ($data['roll_no'] ?? 0),
                (string) ($data['division'] ?? 'A'),
                $year,
                $semester,
            ]);
            $pdo->commit();
            Logger::audit($pdo, Auth::userId(), 'STUDENT_CREATED', "Created {$zprn}");

            if (Settings::get('send_account_ready_email', '1') === '1') {
                $pwInMail = Settings::get('include_temp_password_email', '0') === '1' ? $tempPassword : null;
                Mailer::sendAccountReady($email, trim((string) $data['full_name']), $zprn, $pwInMail);
            }

            Response::json(['success' => true, 'id' => $uid, 'identifier' => $zprn, 'temp_password' => $tempPassword], 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function importStudentsCsv(Request $req): void
    {
        RateLimiter::checkSessionRate('csv_import', 10, 300);
        $file = $req->file('file');
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('Please upload a valid CSV file.', 422);
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            Response::error('Only .csv files are supported.', 422);
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            Response::error('Unable to read uploaded CSV.', 400);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            Response::error('CSV file is empty.', 422);
        }
        $normHeader = array_map(fn($h) => strtolower(trim((string) $h)), $header);
        $requiredCols = ['zprn', 'fullname', 'email', 'year', 'division', 'rollno'];
        foreach ($requiredCols as $col) {
            if (!in_array($col, $normHeader, true)) {
                fclose($handle);
                Response::error("Missing required CSV column: {$col}. Expected: ZPRN, FullName, Email, Year, Division, RollNo", 422);
            }
        }
        $idx = array_flip($normHeader);

        $pdo = Database::getConnection();
        $created = 0;
        $skipped = 0;
        $errors = 0;
        $report = [];

        $checkUser = $pdo->prepare('SELECT id FROM users WHERE identifier = ? OR email = ? LIMIT 1');
        $insUser = $pdo->prepare(
            'INSERT INTO users (identifier, password_hash, role, full_name, email, must_change_password) VALUES (?,?,?,?,?,1)'
        );
        $insStu = $pdo->prepare(
            'INSERT INTO students (user_id, zprn, roll_no, division, year, semester) VALUES (?,?,?,?,?,?)'
        );

        $pdo->beginTransaction();
        try {
            $rowNum = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $zprn     = trim((string) ($row[$idx['zprn']] ?? ''));
                $fullName = trim((string) ($row[$idx['fullname']] ?? ''));
                $email    = trim((string) ($row[$idx['email']] ?? ''));
                $year     = strtoupper(trim((string) ($row[$idx['year']] ?? 'TE-IT')));
                $division = strtoupper(trim((string) ($row[$idx['division']] ?? 'A')));
                $rollNo   = (int) trim((string) ($row[$idx['rollno']] ?? '0'));

                if ($zprn === '' || $fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors++;
                    $report[] = ['row' => $rowNum, 'zprn' => $zprn, 'status' => 'error', 'reason' => 'Invalid ZPRN, FullName, or Email'];
                    continue;
                }
                if (!in_array($year, ['FE-IT', 'SE-IT', 'TE-IT', 'BE-IT'], true)) {
                    $errors++;
                    $report[] = ['row' => $rowNum, 'zprn' => $zprn, 'status' => 'error', 'reason' => "Invalid Year '{$year}' (expected FE-IT, SE-IT, TE-IT, or BE-IT)"];
                    continue;
                }
                if (!in_array($division, ['A', 'B', 'C'], true)) {
                    $division = 'A';
                }

                $semRaw = isset($idx['semester']) ? trim((string) ($row[$idx['semester']] ?? '')) : '';
                $sem = $semRaw !== '' ? (int) $semRaw : self::YEAR_SEMESTERS[$year][0];
                if (!self::isYearSemesterConsistent($year, $sem)) {
                    $errors++;
                    $report[] = ['row' => $rowNum, 'zprn' => $zprn, 'status' => 'error', 'reason' => "Semester {$sem} does not match year {$year}"];
                    continue;
                }

                $email = strtolower($email);
                $checkUser->execute([$zprn, $email]);
                if ($checkUser->fetch()) {
                    $skipped++;
                    $report[] = ['row' => $rowNum, 'zprn' => $zprn, 'status' => 'skipped', 'reason' => 'Duplicate ID or Email'];
                    continue;
                }

                $tempPw = self::generateTempPassword();
                $insUser->execute([$zprn, password_hash($tempPw, PASSWORD_BCRYPT), 'student', $fullName, $email]);
                $uid = (int) $pdo->lastInsertId();
                $insStu->execute([$uid, $zprn, $rollNo, $division, $year, $sem]);
                $created++;
                $report[] = ['row' => $rowNum, 'zprn' => $zprn, 'email' => $email, 'temp_password' => $tempPw, 'status' => 'created', 'reason' => 'Enrolled'];
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fclose($handle);
            throw $e;
        }
        fclose($handle);

        Logger::audit($pdo, Auth::userId(), 'STUDENTS_BULK_IMPORT', "Created: {$created}, Skipped: {$skipped}, Errors: {$errors}");
        Response::json([
            'success' => true,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
            'rows' => $report,
        ]);
    }

    public function downloadStudentTemplate(Request $req): void
    {
        Response::csv(
            'epap_student_import_template.csv',
            ['ZPRN', 'FullName', 'Email', 'Year', 'Division', 'RollNo'],
            [
                ['STU-2026-001', 'Student One', 'student1@example.edu', 'TE-IT', 'A', '1'],
                ['STU-2026-002', 'Student Two', 'student2@example.edu', 'TE-IT', 'B', '2'],
            ]
        );
    }

    public function faculty(Request $req): void
    {
        $pdo = Database::getConnection();
        $rows = $pdo->query(
            'SELECT f.*, u.full_name, u.email, u.is_active
             FROM faculty f JOIN users u ON f.user_id = u.id
             ORDER BY u.full_name'
        )->fetchAll();
        Response::json($rows);
    }

    public function subjects(Request $req): void
    {
        $pdo = Database::getConnection();
        $rows = $pdo->query('SELECT * FROM subjects ORDER BY semester, code')->fetchAll();
        Response::json($rows);
    }

    public function allocations(Request $req): void
    {
        $pdo = Database::getConnection();
        $rows = $pdo->query(
            "SELECT fa.*, f.faculty_id AS staff_code, u.full_name AS faculty_name,
                    s.code AS subject_code, s.name AS subject_name,
                    (SELECT COUNT(*) FROM students st WHERE st.year = fa.year AND st.division = fa.division) AS assigned_students
             FROM faculty_allocations fa
             JOIN faculty f ON fa.faculty_id = f.id
             JOIN users u ON f.user_id = u.id
             JOIN subjects s ON fa.subject_id = s.id
             ORDER BY fa.year, fa.division, s.code"
        )->fetchAll();
        Response::json($rows);
    }

    public function createAllocation(Request $req): void
    {
        $data = Validator::validate($req->body(), [
            'faculty_id'    => 'required|int',
            'subject_id'    => 'required|int',
            'year'          => 'required|in:SE-IT,TE-IT,BE-IT',
            'division'      => 'required|in:A,B,C',
            'academic_year' => 'max:20',
        ]);

        $pdo = Database::getConnection();
        $acadYear = (string) ($data['academic_year'] ?? '2025-26');

        $chk = $pdo->prepare(
            'SELECT id FROM faculty_allocations WHERE faculty_id=? AND subject_id=? AND year=? AND division=? AND academic_year=?'
        );
        $chk->execute([(int) $data['faculty_id'], (int) $data['subject_id'], $data['year'], $data['division'], $acadYear]);
        if ($chk->fetch()) {
            Response::error('This faculty allocation already exists.', 409);
        }

        $pdo->prepare(
            "INSERT INTO faculty_allocations (faculty_id, subject_id, year, division, academic_year, status)
             VALUES (?,?,?,?,?,'active')"
        )->execute([(int) $data['faculty_id'], (int) $data['subject_id'], $data['year'], $data['division'], $acadYear]);

        $newId = (int) $pdo->lastInsertId();
        Logger::audit($pdo, Auth::userId(), 'FACULTY_ALLOCATION_CREATED', "Allocation #{$newId} ({$data['year']} Div {$data['division']})");
        Response::json(['success' => true, 'id' => $newId], 201);
    }

    public function deleteAllocation(Request $req, array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM faculty_allocations WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            Response::error('Allocation not found.', 404);
        }
        Logger::audit($pdo, Auth::userId(), 'FACULTY_ALLOCATION_DELETED', "Allocation #{$id}");
        Response::json(['success' => true]);
    }

    public function exams(Request $req): void
    {
        $pdo = Database::getConnection();
        $rows = $pdo->query(
            'SELECT er.*,
                    COUNT(DISTINCT sm.student_id) AS candidates_count,
                    ROUND(AVG(sm.passed) * 100, 1) AS pass_percentage
             FROM exam_results_release er
             LEFT JOIN semester_marks sm ON sm.exam_id = er.id
             GROUP BY er.id
             ORDER BY er.semester DESC, er.id DESC'
        )->fetchAll();
        Response::json($rows);
    }

    public function updateExamStatus(Request $req, array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $data = Validator::validate($req->body(), [
            'status' => 'required|in:draft,under_moderation,published,locked',
        ]);
        $status = (string) $data['status'];

        $pdo = Database::getConnection();
        $chk = $pdo->prepare('SELECT exam_name FROM exam_results_release WHERE id = ?');
        $chk->execute([$id]);
        $exam = $chk->fetch();
        if (!$exam) {
            Response::error('Exam session not found.', 404);
        }

        if ($status === 'published') {
            $pdo->prepare('UPDATE exam_results_release SET status = ?, published_at = NOW(), published_by = ? WHERE id = ?')
                ->execute([$status, Auth::userId(), $id]);
        } else {
            $pdo->prepare('UPDATE exam_results_release SET status = ? WHERE id = ?')->execute([$status, $id]);
        }

        Logger::audit($pdo, Auth::userId(), 'EXAM_STATUS_UPDATED', "{$exam['exam_name']} -> {$status}");
        Response::json(['success' => true, 'status' => $status]);
    }

    public function importMarksCsv(Request $req): void
    {
        $file = $req->file('file');
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('Please upload a CSV file.', 422);
        }
        $handle = fopen($file['tmp_name'], 'r');
        $header = $handle ? fgetcsv($handle) : false;
        if (!$header) {
            Response::error('CSV is empty.', 422);
        }
        $idx = array_flip(array_map(fn($h) => strtolower(trim((string) $h)), $header));
        foreach (['zprn', 'subjectcode', 'semester', 'insem', 'endsem', 'practical'] as $col) {
            if (!isset($idx[$col])) {
                fclose($handle);
                Response::error("Missing column: {$col}", 422);
            }
        }

        $pdo = Database::getConnection();
        $stuStmt = $pdo->prepare('SELECT id, year FROM students WHERE zprn = ?');
        $subStmt = $pdo->prepare('SELECT * FROM subjects WHERE code = ?');
        $exStmt  = $pdo->prepare('SELECT id FROM exam_results_release WHERE year = ? AND semester = ? LIMIT 1');
        $upStmt  = $pdo->prepare(
            'INSERT INTO semester_marks (student_id, subject_id, exam_id, semester, in_sem, end_sem, practical, total_marks, max_marks, grade, grade_points, passed)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE in_sem=VALUES(in_sem), end_sem=VALUES(end_sem), practical=VALUES(practical),
               total_marks=VALUES(total_marks), max_marks=VALUES(max_marks), grade=VALUES(grade), grade_points=VALUES(grade_points), passed=VALUES(passed)'
        );

        $affectedStudents = [];
        $imported = 0;
        $pdo->beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $zprn = trim((string) ($row[$idx['zprn']] ?? ''));
                $code = trim((string) ($row[$idx['subjectcode']] ?? ''));
                $sem  = (int) ($row[$idx['semester']] ?? 5);
                $inS  = (int) ($row[$idx['insem']] ?? 0);
                $endS = (int) ($row[$idx['endsem']] ?? 0);
                $prac = (int) ($row[$idx['practical']] ?? 0);

                $stuStmt->execute([$zprn]);
                $stu = $stuStmt->fetch();
                $subStmt->execute([$code]);
                $sub = $subStmt->fetch();
                if (!$stu || !$sub) {
                    continue;
                }

                $exStmt->execute([$stu['year'], $sem]);
                $examId = $exStmt->fetchColumn() ?: null;
                [$total, $maxT, $grade, $gp, $passed] = SppuGrading::computeSubjectGrade(
                    $inS, $endS, $prac, (int) $sub['max_in_sem'], (int) $sub['max_end_sem'], (int) $sub['max_practical']
                );
                $upStmt->execute([(int) $stu['id'], (int) $sub['id'], $examId, $sem, $inS, $endS, $prac, $total, $maxT, $grade, $gp, $passed]);
                $affectedStudents[(int) $stu['id']] = true;
                $imported++;
            }
            foreach (array_keys($affectedStudents) as $sid) {
                SppuGrading::recalculateStudentAverages($pdo, $sid);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fclose($handle);
            throw $e;
        }
        fclose($handle);
        Logger::audit($pdo, Auth::userId(), 'MARKS_BULK_IMPORT', "Imported {$imported} mark records");
        Response::json(['success' => true, 'imported' => $imported]);
    }

    public function auditLogs(Request $req): void
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT al.*, u.full_name, u.identifier, u.role
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE 1=1';
        $bind = [];
        if ($req->query('action')) {
            $sql .= ' AND al.action LIKE ?';
            $bind[] = '%' . $req->query('action') . '%';
        }
        if ($req->query('role')) {
            $sql .= ' AND u.role = ?';
            $bind[] = $req->query('role');
        }
        $sql .= ' ORDER BY al.created_at DESC LIMIT 100';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        Response::json($stmt->fetchAll());
    }

    public function exportAuditLogs(Request $req): void
    {
        $pdo = Database::getConnection();
        $rows = $pdo->query(
            'SELECT al.created_at, COALESCE(u.identifier, "SYSTEM"), COALESCE(u.role, "SYSTEM"), al.action, al.detail, al.ip, al.status
             FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id
             ORDER BY al.created_at DESC LIMIT 1000'
        )->fetchAll(PDO::FETCH_NUM);

        Logger::audit($pdo, Auth::userId(), 'AUDIT_LOGS_EXPORTED', 'HOD exported audit logs CSV');
        Response::csv('epap_audit_logs_' . date('Ymd') . '.csv', ['Timestamp', 'Actor ID', 'Role', 'Action', 'Detail', 'IP', 'Status'], $rows);
    }

    public function createFaculty(Request $req): void
    {
        $data = Validator::validate($req->body(), [
            'full_name'   => 'required|min:2|max:100',
            'email'       => 'required|email|max:120',
            'faculty_id'  => 'required|min:3|max:60',
            'designation' => 'max:100',
            'department'  => 'max:50',
        ]);

        $pdo = Database::getConnection();
        $fid = trim((string) $data['faculty_id']);
        $email = strtolower(trim((string) $data['email']));

        $dup = $pdo->prepare('SELECT id FROM users WHERE identifier = ? OR email = ? LIMIT 1');
        $dup->execute([$fid, $email]);
        if ($dup->fetch()) {
            Response::error('A user with this Faculty ID or email already exists.', 409);
        }

        $tempPassword = self::generateTempPassword();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO users (identifier, password_hash, role, full_name, email, must_change_password) VALUES (?,?,?,?,?,1)'
            )->execute([$fid, password_hash($tempPassword, PASSWORD_BCRYPT), 'faculty', trim((string) $data['full_name']), $email]);
            $uid = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO faculty (user_id, faculty_id, designation, department) VALUES (?,?,?,?)'
            )->execute([
                $uid,
                $fid,
                trim((string) ($data['designation'] ?? 'Assistant Professor')),
                trim((string) ($data['department'] ?? Settings::get('department_name', 'Information Technology'))),
            ]);
            $facId = (int) $pdo->lastInsertId();
            $pdo->commit();

            Logger::audit($pdo, Auth::userId(), 'FACULTY_CREATED', "Created {$fid}");
            if (Settings::get('send_account_ready_email', '1') === '1') {
                $pwInMail = Settings::get('include_temp_password_email', '0') === '1' ? $tempPassword : null;
                Mailer::sendAccountReady($email, trim((string) $data['full_name']), $fid, $pwInMail);
            }
            Response::json(['success' => true, 'id' => $facId, 'user_id' => $uid, 'faculty_id' => $fid, 'temp_password' => $tempPassword], 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function createSubject(Request $req): void
    {
        $data = Validator::validate($req->body(), [
            'code'          => 'required|min:2|max:20',
            'name'          => 'required|min:2|max:120',
            'semester'      => 'required|int|min:1|max:8',
            'credits'       => 'int|min:1|max:10',
            'max_in_sem'    => 'int|min:0|max:100',
            'max_end_sem'   => 'int|min:0|max:200',
            'max_practical' => 'int|min:0|max:200',
        ]);

        $pdo = Database::getConnection();
        $code = trim((string) $data['code']);
        $chk = $pdo->prepare('SELECT id FROM subjects WHERE code = ?');
        $chk->execute([$code]);
        if ($chk->fetch()) {
            Response::error('Subject with this code already exists.', 409);
        }

        $pdo->prepare(
            'INSERT INTO subjects (code, name, semester, credits, max_in_sem, max_end_sem, max_practical) VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $code,
            trim((string) $data['name']),
            (int) $data['semester'],
            (int) ($data['credits'] ?? 4),
            (int) ($data['max_in_sem'] ?? 30),
            (int) ($data['max_end_sem'] ?? 70),
            (int) ($data['max_practical'] ?? 50),
        ]);
        $id = (int) $pdo->lastInsertId();
        Logger::audit($pdo, Auth::userId(), 'SUBJECT_CREATED', "Created {$code}");
        Response::json(['success' => true, 'id' => $id], 201);
    }

    public function createExam(Request $req): void
    {
        $data = Validator::validate($req->body(), [
            'exam_name'       => 'required|min:3|max:160',
            'year'            => 'required|in:SE-IT,TE-IT,BE-IT',
            'semester'        => 'required|int|min:1|max:8',
            'exam_type'       => 'in:in_sem,end_sem,combined',
            'academic_year'   => 'max:20',
            'conducted_label' => 'max:60',
        ]);

        $pdo = Database::getConnection();
        $examType = (string) ($data['exam_type'] ?? 'combined');
        $acadYear = (string) ($data['academic_year'] ?? Settings::get('academic_year', '2025-26'));

        $chk = $pdo->prepare('SELECT id FROM exam_results_release WHERE year=? AND semester=? AND exam_type=? AND academic_year=?');
        $chk->execute([$data['year'], (int) $data['semester'], $examType, $acadYear]);
        if ($chk->fetch()) {
            Response::error('An exam session for this year, semester, type, and academic year already exists.', 409);
        }

        $pdo->prepare(
            'INSERT INTO exam_results_release (exam_name, year, semester, exam_type, academic_year, conducted_label, status)
             VALUES (?,?,?,?,?,?,\'draft\')'
        )->execute([
            trim((string) $data['exam_name']),
            $data['year'],
            (int) $data['semester'],
            $examType,
            $acadYear,
            trim((string) ($data['conducted_label'] ?? '')),
        ]);
        $id = (int) $pdo->lastInsertId();
        Logger::audit($pdo, Auth::userId(), 'EXAM_CREATED', "Exam #{$id}: {$data['exam_name']}");
        Response::json(['success' => true, 'id' => $id], 201);
    }

    public function resetUserPassword(Request $req, array $params): void
    {
        $userId = (int) ($params['id'] ?? 0);
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, identifier, full_name, email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Response::error('User not found.', 404);
        }

        $tempPassword = self::generateTempPassword();
        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
            ->execute([password_hash($tempPassword, PASSWORD_BCRYPT), $userId]);

        Logger::audit($pdo, Auth::userId(), 'ADMIN_PASSWORD_RESET', "Reset password for {$user['identifier']}");
        Response::json(['success' => true, 'identifier' => $user['identifier'], 'temp_password' => $tempPassword]);
    }

    public function updateUserEmail(Request $req, array $params): void
    {
        $userId = (int) ($params['id'] ?? 0);
        $data = Validator::validate($req->body(), [
            'email' => 'required|email|max:120',
        ]);
        if (empty($data['confirm'])) {
            Response::error('Confirmation is required to change a user email address.', 422);
        }

        $newEmail = strtolower(trim((string) $data['email']));
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT id, identifier, email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Response::error('User not found.', 404);
        }

        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $dup->execute([$newEmail, $userId]);
        if ($dup->fetch()) {
            Response::error('Email address is already in use by another account.', 409);
        }

        $oldEmail = $user['email'];
        $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$newEmail, $userId]);
        Logger::audit($pdo, Auth::userId(), 'USER_EMAIL_CHANGED', "{$user['identifier']}: {$oldEmail} -> {$newEmail}");
        Response::json(['success' => true, 'email' => $newEmail]);
    }

    public function getSettings(Request $req): void
    {
        Response::json(Settings::all(Database::getConnection()));
    }

    public function updateSettings(Request $req): void
    {
        $body = $req->body();
        $allowed = array_keys(Settings::DEFAULTS);
        $updates = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $body)) {
                $val = trim((string) $body[$key]);
                if ($key === 'support_email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    Response::error('support_email must be a valid email address.', 422);
                }
                $updates[$key] = $val;
            }
        }
        $pdo = Database::getConnection();
        Settings::setMany($pdo, $updates);
        Logger::audit($pdo, Auth::userId(), 'SETTINGS_UPDATED', 'Updated keys: ' . implode(', ', array_keys($updates)));
        Response::json(['success' => true, 'settings' => Settings::all($pdo)]);
    }

    public function publicSettings(Request $req): void
    {
        Response::json(Settings::publicSettings());
    }

    private static function generateTempPassword(int $length = 12): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $symbols = '@#$%&*!';
        $all = $upper . $lower . $digits . $symbols;

        $pw = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];
        for ($i = 4; $i < $length; $i++) {
            $pw[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($pw);
        return implode('', $pw);
    }
}
