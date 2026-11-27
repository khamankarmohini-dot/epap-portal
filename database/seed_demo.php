<?php
/**
 * EPAP - Optional Demo Data Seeder (Explicit opt-in only: php setup.php --demo)
 */
declare(strict_types=1);

use App\Core\SppuGrading;

function runSeedDemo(PDO $pdo): void
{
    $users = [
        ['DEMO-STU-001', 'DemoStudent@2026', 'student', 'Aarav Kulkarni',    'aarav.demo@example.edu', 0],
        ['DEMO-STU-002', 'DemoStudent@2026', 'student', 'Diya Deshmukh',     'diya.demo@example.edu', 0],
        ['DEMO-STU-003', 'DemoStudent@2026', 'student', 'Kabir Joshi',       'kabir.demo@example.edu', 0],
        ['DEMO-FAC-001', 'DemoFaculty@2026', 'faculty', 'Prof. S. Patil',    's.patil.demo@example.edu', 0],
        ['DEMO-FAC-002', 'DemoFaculty@2026', 'faculty', 'Prof. K. Joshi',    'k.joshi.demo@example.edu', 0],
        ['DEMO-ADM-001', 'DemoAdmin@2026',   'admin',   'Department HOD',    'hod.demo@example.edu', 0],
    ];

    $stmtUser = $pdo->prepare(
        'INSERT IGNORE INTO users (identifier, password_hash, role, full_name, email, must_change_password) VALUES (?,?,?,?,?,?)'
    );
    foreach ($users as [$ident, $pw, $role, $name, $email, $mustChange]) {
        $stmtUser->execute([$ident, password_hash($pw, PASSWORD_BCRYPT), $role, $name, $email, $mustChange]);
    }

    $getUserId = function (string $ident) use ($pdo): int {
        $s = $pdo->prepare('SELECT id FROM users WHERE identifier = ?');
        $s->execute([$ident]);
        return (int) $s->fetchColumn();
    };

    $students = [
        ['DEMO-STU-001', 'T23104001', 1, 'A', 'TE-IT', 5, 8.60, 8.85],
        ['DEMO-STU-002', 'T23104002', 2, 'A', 'TE-IT', 5, 7.85, 8.00],
        ['DEMO-STU-003', 'S24103003', 3, 'B', 'SE-IT', 3, 7.90, 7.90],
    ];
    $stmtStu = $pdo->prepare(
        'INSERT IGNORE INTO students (user_id, zprn, seat_number, roll_no, division, year, semester, cgpa, current_sgpa)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    foreach ($students as [$zprn, $seat, $roll, $div, $year, $sem, $cgpa, $sgpa]) {
        $stmtStu->execute([$getUserId($zprn), $zprn, $seat, $roll, $div, $year, $sem, $cgpa, $sgpa]);
    }

    $faculty = [
        ['DEMO-FAC-001', 'Assistant Professor', 'Information Technology'],
        ['DEMO-FAC-002', 'Associate Professor', 'Information Technology'],
    ];
    $stmtFac = $pdo->prepare(
        'INSERT IGNORE INTO faculty (user_id, faculty_id, designation, department) VALUES (?,?,?,?)'
    );
    foreach ($faculty as [$fid, $desig, $dept]) {
        $stmtFac->execute([$getUserId($fid), $fid, $desig, $dept]);
    }

    $subjects = [
        ['214441', 'Discrete Mathematics',                   3, 4, 30, 70, 0],
        ['214442', 'Data Structures & Algorithms',           3, 4, 30, 70, 50],
        ['214451', 'Processor Architecture',                 4, 4, 30, 70, 50],
        ['214452', 'Operating Systems',                      4, 4, 30, 70, 50],
        ['314441', 'Database Management Systems (DBMS)',     5, 4, 30, 70, 50],
        ['314442', 'Computer Networks & Security (CNS)',     5, 4, 30, 70, 50],
        ['314443', 'Web Technology (WT)',                    5, 4, 30, 70, 50],
    ];
    $stmtSub = $pdo->prepare(
        'INSERT IGNORE INTO subjects (code, name, semester, credits, max_in_sem, max_end_sem, max_practical)
         VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($subjects as $s) {
        $stmtSub->execute($s);
    }

    $getFacId = function (string $fid) use ($pdo): int {
        $s = $pdo->prepare('SELECT id FROM faculty WHERE faculty_id = ?');
        $s->execute([$fid]);
        return (int) $s->fetchColumn();
    };
    $getSubId = function (string $code) use ($pdo): int {
        $s = $pdo->prepare('SELECT id FROM subjects WHERE code = ?');
        $s->execute([$code]);
        return (int) $s->fetchColumn();
    };
    $getStuId = function (string $zprn) use ($pdo): int {
        $s = $pdo->prepare('SELECT id FROM students WHERE zprn = ?');
        $s->execute([$zprn]);
        return (int) $s->fetchColumn();
    };

    $fac1 = $getFacId('DEMO-FAC-001');
    $fac2 = $getFacId('DEMO-FAC-002');
    $admUid = $getUserId('DEMO-ADM-001');

    $allocations = [
        [$fac1, $getSubId('314441'), 'TE-IT', 'A', '2025-26'],
        [$fac1, $getSubId('314442'), 'TE-IT', 'A', '2025-26'],
        [$fac2, $getSubId('314443'), 'TE-IT', 'A', '2025-26'],
    ];
    $stmtAlloc = $pdo->prepare(
        "INSERT IGNORE INTO faculty_allocations (faculty_id, subject_id, year, division, academic_year, status)
         VALUES (?,?,?,?,?,'active')"
    );
    foreach ($allocations as $a) {
        $stmtAlloc->execute($a);
    }

    $now = time();
    $activities = [
        [$fac1, $getSubId('314441'), 'Lab Assignment: PL/SQL Triggers', 'Lab Practical Journal', 'Write PL/SQL trigger and stored procedure.', 25, date('Y-m-d H:i:s', $now + 4 * 86400), 'TE-IT', 'A'],
        [$fac2, $getSubId('314443'), 'Mini-Project Milestone', 'Mini-Project Milestone', 'Build a responsive web portal with PDO backend.', 50, date('Y-m-d H:i:s', $now + 6 * 86400), 'TE-IT', 'A'],
    ];
    $stmtActCheck = $pdo->prepare('SELECT id FROM activities WHERE title = ? AND faculty_id = ?');
    $stmtActIns = $pdo->prepare(
        'INSERT INTO activities (faculty_id, subject_id, title, activity_type, description, max_marks, deadline, year, division)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    foreach ($activities as $a) {
        $stmtActCheck->execute([$a[2], $a[0]]);
        if (!$stmtActCheck->fetchColumn()) {
            $stmtActIns->execute($a);
        }
    }

    $exams = [
        ['TE-IT Semester III Examination', 'TE-IT', 3, 'combined', '2024-25', 'Dec 2024', 'published', date('Y-m-d H:i:s', $now - 150 * 86400), $admUid],
        ['TE-IT Semester V Examination',   'TE-IT', 5, 'combined', '2025-26', 'Oct 2026', 'published', date('Y-m-d H:i:s', $now - 5 * 86400),   $admUid],
    ];
    $stmtExam = $pdo->prepare(
        'INSERT IGNORE INTO exam_results_release (exam_name, year, semester, exam_type, academic_year, conducted_label, status, published_at, published_by)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    foreach ($exams as $ex) {
        $stmtExam->execute($ex);
    }

    $stu1 = $getStuId('DEMO-STU-001');
    $getExamId = function (string $year, int $sem, string $type) use ($pdo): ?int {
        $s = $pdo->prepare('SELECT id FROM exam_results_release WHERE year=? AND semester=? AND exam_type=? LIMIT 1');
        $s->execute([$year, $sem, $type]);
        $v = $s->fetchColumn();
        return $v ? (int) $v : null;
    };

    $marksEntries = [
        [$stu1, '214441', 3, 24, 59, 0,  8.30, 'TE-IT'],
        [$stu1, '214442', 3, 26, 62, 44, 8.30, 'TE-IT'],
        [$stu1, '314441', 5, 27, 59, 45, 8.85, 'TE-IT'],
        [$stu1, '314442', 5, 24, 54, 42, 8.85, 'TE-IT'],
        [$stu1, '314443', 5, 29, 64, 48, 8.85, 'TE-IT'],
    ];
    $stmtMarks = $pdo->prepare(
        'INSERT IGNORE INTO semester_marks
         (student_id, subject_id, exam_id, semester, in_sem, end_sem, practical, total_marks, max_marks, grade, grade_points, passed, sgpa)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $subInfoStmt = $pdo->prepare('SELECT id, max_in_sem, max_end_sem, max_practical FROM subjects WHERE code = ?');
    foreach ($marksEntries as [$stId, $subCode, $sem, $inS, $endS, $prac, $sgpaVal, $yr]) {
        $subInfoStmt->execute([$subCode]);
        $sub = $subInfoStmt->fetch();
        if (!$sub) {
            continue;
        }
        $maxTotal = (int) $sub['max_in_sem'] + (int) $sub['max_end_sem'] + (int) $sub['max_practical'];
        $total = $inS + $endS + $prac;
        [$grade, $gp, $passed] = SppuGrading::compute($total, $maxTotal, $endS, (int) $sub['max_end_sem']);
        $examId = $getExamId($yr, $sem, 'combined');
        $stmtMarks->execute([$stId, (int) $sub['id'], $examId, $sem, $inS, $endS, $prac, $total, $maxTotal, $grade, $gp, $passed, $sgpaVal]);
    }
}
