<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Judge0Client;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use PDO;

class PracticeController
{
    private function getStudentId(PDO $pdo): int
    {
        $stmt = $pdo->prepare('SELECT id FROM students WHERE user_id = ? LIMIT 1');
        $stmt->execute([(int) Auth::userId()]);
        $id = (int) $stmt->fetchColumn();
        if (!$id) {
            Response::error('Student profile not found.', 404);
        }
        return $id;
    }

    public function problems(Request $req): void
    {
        $pdo = Database::getConnection();
        $stuId = $this->getStudentId($pdo);
        $track = $req->query('track', 'dsa');
        $diff = $req->query('difficulty');
        $search = trim((string) ($req->query('q', '')));

        $sql = "SELECT p.id, p.problem_number, p.subject_track, p.title, p.difficulty, p.topic,
                       EXISTS(SELECT 1 FROM practice_submissions ps WHERE ps.problem_id = p.id AND ps.student_id = ? AND ps.verdict = 'Accepted') AS is_solved
                FROM practice_problems p
                WHERE 1=1";
        $bind = [$stuId];

        if ($track && in_array($track, ['dsa', 'oop', 'dbms'], true)) {
            $sql .= ' AND p.subject_track = ?';
            $bind[] = $track;
        }
        if ($diff && in_array($diff, ['Easy', 'Medium', 'Hard'], true)) {
            $sql .= ' AND p.difficulty = ?';
            $bind[] = $diff;
        }
        if ($search !== '') {
            $sql .= ' AND (p.title LIKE ? OR p.topic LIKE ?)';
            $bind[] = "%{$search}%";
            $bind[] = "%{$search}%";
        }
        $sql .= ' ORDER BY p.problem_number ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $problems = $stmt->fetchAll();

        $totalInTrack = count($problems);
        $solvedInTrack = count(array_filter($problems, fn($p) => (bool) $p['is_solved']));

        Response::json([
            'problems' => $problems,
            'solved_count' => $solvedInTrack,
            'total_count' => $totalInTrack,
            'judge0_configured' => Judge0Client::isConfigured(),
        ]);
    }

    public function problemDetail(Request $req, array $params): void
    {
        $pdo = Database::getConnection();
        $id = (int) ($params['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM practice_problems WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) {
            Response::error('Problem not found.', 404);
        }
        $p['constraints'] = json_decode((string) $p['constraints_json'], true) ?: [];
        $p['examples'] = json_decode((string) $p['examples_json'], true) ?: [];
        $p['starter_code'] = json_decode((string) $p['starter_code_json'], true) ?: [];
        unset($p['constraints_json'], $p['examples_json'], $p['starter_code_json']);

        $tcStmt = $pdo->prepare('SELECT id, input_data, expected_output FROM practice_testcases WHERE problem_id = ? AND is_sample = 1');
        $tcStmt->execute([$id]);
        $p['sample_testcases'] = $tcStmt->fetchAll();

        Response::json($p);
    }

    public function run(Request $req): void
    {
        RateLimiter::checkSessionRate('practice_run', 20, 60);
        $data = Validator::validate($req->body(), [
            'problem_id' => 'required|int',
            'language'   => 'required|in:python,java,cpp,c',
            'code'       => 'required|min:1|max:50000',
        ]);

        $pdo = Database::getConnection();
        $pid = (int) $data['problem_id'];
        $tcStmt = $pdo->prepare('SELECT input_data, expected_output FROM practice_testcases WHERE problem_id = ? AND is_sample = 1 LIMIT 1');
        $tcStmt->execute([$pid]);
        $tc = $tcStmt->fetch();
        $stdin = $tc ? $tc['input_data'] : '';

        $result = Judge0Client::execute((string) $data['language'], (string) $data['code'], $stdin);
        $result['expected_output'] = $tc ? $tc['expected_output'] : null;
        $result['input'] = $stdin;
        Response::json($result);
    }

    public function submit(Request $req): void
    {
        RateLimiter::checkSessionRate('practice_submit', 15, 60);
        $data = Validator::validate($req->body(), [
            'problem_id' => 'required|int',
            'language'   => 'required|in:python,java,cpp,c',
            'code'       => 'required|min:1|max:50000',
        ]);

        $pdo = Database::getConnection();
        $stuId = $this->getStudentId($pdo);
        $pid = (int) $data['problem_id'];

        $tcStmt = $pdo->prepare('SELECT input_data, expected_output FROM practice_testcases WHERE problem_id = ?');
        $tcStmt->execute([$pid]);
        $testcases = $tcStmt->fetchAll();
        $totalCount = count($testcases);

        if (!Judge0Client::isConfigured()) {
            $pdo->prepare(
                'INSERT INTO practice_submissions (student_id, problem_id, language, code, verdict, passed_count, total_count)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$stuId, $pid, $data['language'], $data['code'], 'Demo Mode (Not Executed)', 0, $totalCount]);

            Response::json([
                'mode' => 'demo',
                'configured' => false,
                'verdict' => 'Demo Mode (Not Executed)',
                'passed' => 0,
                'total' => $totalCount,
                'message' => 'Judge0 is not configured in .env. Your submission was saved in Demo Mode without marking the problem solved.',
            ]);
        }

        $passed = 0;
        $maxTime = 0;
        $verdict = 'Accepted';
        foreach ($testcases as $tc) {
            $res = Judge0Client::execute((string) $data['language'], (string) $data['code'], $tc['input_data']);
            $maxTime = max($maxTime, (int) ($res['time_ms'] ?? 0));
            if (($res['status_id'] ?? 0) === 3 && trim((string) ($res['stdout'] ?? '')) === trim((string) $tc['expected_output'])) {
                $passed++;
            } else {
                $verdict = $res['status'] ?? 'Wrong Answer';
                break;
            }
        }

        $pdo->prepare(
            'INSERT INTO practice_submissions (student_id, problem_id, language, code, verdict, runtime_ms, passed_count, total_count)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$stuId, $pid, $data['language'], $data['code'], $verdict, $maxTime, $passed, $totalCount]);

        Logger::audit($pdo, Auth::userId(), 'PRACTICE_SUBMIT', "Problem {$pid}: {$verdict} ({$passed}/{$totalCount})");
        Response::json([
            'mode' => 'judge0',
            'configured' => true,
            'verdict' => $verdict,
            'passed' => $passed,
            'total' => $totalCount,
            'runtime_ms' => $maxTime,
        ]);
    }

    public function mySubmissions(Request $req): void
    {
        $pdo = Database::getConnection();
        $stuId = $this->getStudentId($pdo);
        $stmt = $pdo->prepare(
            'SELECT ps.id, ps.problem_id, p.title, ps.language, ps.verdict, ps.runtime_ms, ps.passed_count, ps.total_count, ps.created_at
             FROM practice_submissions ps
             JOIN practice_problems p ON ps.problem_id = p.id
             WHERE ps.student_id = ?
             ORDER BY ps.created_at DESC LIMIT 30'
        );
        $stmt->execute([$stuId]);
        Response::json($stmt->fetchAll());
    }
}
