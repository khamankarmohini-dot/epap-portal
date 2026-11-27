<?php
/**
 * EPAP API Front Controller (/api/*)
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\AnalyticsController;
use App\Controllers\AuthController;
use App\Controllers\FacultyController;
use App\Controllers\PracticeController;
use App\Controllers\StudentController;
use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthMiddleware;

$router = new Router();
$request = new Request();

// Health check
$router->get('/health', fn() => Response::json(['status' => 'ok']));
$router->get('/health/deep', function (Request $req): void {
    $expected = (string) Env::get('HEALTH_KEY', '');
    $given = (string) ($req->header('X-Health-Key') ?? $req->query('key', ''));
    if ($expected === '' || !hash_equals($expected, $given)) {
        Response::error('Forbidden', 403);
    }
    try {
        Database::getConnection()->query('SELECT 1');
        Response::json(['status' => 'ok', 'db' => 'ok']);
    } catch (\Throwable $e) {
        Response::error('Database unavailable: ' . Database::classifyError($e), 503);
    }
});

// Public branding/settings
$admin = new AdminController();
$router->get('/settings/public', [$admin, 'publicSettings']);

// Auth routes
$auth = new AuthController();
$router->get('/auth/csrf', [$auth, 'csrf']);
$router->get('/auth/otp-session', [$auth, 'otpSession']);
$router->post('/auth/login', [$auth, 'login'], [AuthMiddleware::csrf()]);
$router->post('/auth/verify-otp', [$auth, 'verifyOtp'], [AuthMiddleware::csrf()]);
$router->post('/auth/resend-otp', [$auth, 'resendOtp'], [AuthMiddleware::csrf()]);
$router->post('/auth/logout', [$auth, 'logout'], [AuthMiddleware::csrf()]);
$router->get('/auth/me', [$auth, 'me']);
$router->post('/auth/change-password', [$auth, 'changePassword'], [AuthMiddleware::authOnly()]);
$router->post('/auth/forgot-password', [$auth, 'forgotPassword'], [AuthMiddleware::csrf()]);
$router->post('/auth/reset-password', [$auth, 'resetPassword'], [AuthMiddleware::csrf()]);

// Student routes
$student = new StudentController();
$stuMw = [AuthMiddleware::role('student')];
$router->get('/student/summary', [$student, 'summary'], $stuMw);
$router->get('/student/profile', [$student, 'profile'], $stuMw);
$router->get('/student/stats', [$student, 'stats'], $stuMw);
$router->get('/student/activities', [$student, 'activities'], $stuMw);
$router->post('/student/submit', [$student, 'submit'], $stuMw);
$router->get('/student/submissions/{id}/download', [$student, 'downloadSubmission'], $stuMw);
$router->get('/student/results', [$student, 'results'], $stuMw);
$router->get('/student/notifications', [$student, 'notifications'], $stuMw);
$router->post('/student/notifications/read', [$student, 'markNotificationsRead'], $stuMw);

// Practice Arena routes (student)
$practice = new PracticeController();
$router->get('/practice/problems', [$practice, 'problems'], $stuMw);
$router->get('/practice/problems/{id}', [$practice, 'problemDetail'], $stuMw);
$router->post('/practice/run', [$practice, 'run'], $stuMw);
$router->post('/practice/submit', [$practice, 'submit'], $stuMw);
$router->get('/practice/my-submissions', [$practice, 'mySubmissions'], $stuMw);

// Faculty routes
$faculty = new FacultyController();
$facMw = [AuthMiddleware::role('faculty')];
$router->get('/faculty/summary', [$faculty, 'summary'], $facMw);
$router->get('/faculty/profile', [$faculty, 'profile'], $facMw);
$router->get('/faculty/allocations', [$faculty, 'allocations'], $facMw);
$router->get('/faculty/stats', [$faculty, 'stats'], $facMw);
$router->get('/faculty/submissions', [$faculty, 'submissions'], $facMw);
$router->post('/faculty/grade', [$faculty, 'grade'], $facMw);
$router->get('/faculty/activities', [$faculty, 'activities'], $facMw);
$router->post('/faculty/activities', [$faculty, 'createActivity'], $facMw);
$router->delete('/faculty/activities/{id}', [$faculty, 'deleteActivity'], $facMw);
$router->get('/faculty/submissions/{id}/download', [$faculty, 'downloadSubmission'], $facMw);
$router->get('/faculty/grades/export', [$faculty, 'exportGradesCsv'], $facMw);

// Admin / HOD routes
$admMw = [AuthMiddleware::role('admin')];
$router->get('/admin/summary', [$admin, 'summary'], $admMw);
$router->get('/admin/stats', [$admin, 'stats'], $admMw);
$router->get('/admin/students', [$admin, 'students'], $admMw);
$router->get('/admin/students/inconsistent', [$admin, 'inconsistentStudents'], $admMw);
$router->post('/admin/students/{id}/fix-year-semester', [$admin, 'fixStudentYearSemester'], $admMw);
$router->post('/admin/students', [$admin, 'createStudent'], $admMw);
$router->post('/admin/students/import', [$admin, 'importStudentsCsv'], $admMw);
$router->get('/admin/students/template', [$admin, 'downloadStudentTemplate'], $admMw);
$router->get('/admin/faculty', [$admin, 'faculty'], $admMw);
$router->post('/admin/faculty', [$admin, 'createFaculty'], $admMw);
$router->get('/admin/subjects', [$admin, 'subjects'], $admMw);
$router->post('/admin/subjects', [$admin, 'createSubject'], $admMw);
$router->get('/admin/allocations', [$admin, 'allocations'], $admMw);
$router->post('/admin/allocations', [$admin, 'createAllocation'], $admMw);
$router->delete('/admin/allocations/{id}', [$admin, 'deleteAllocation'], $admMw);
$router->get('/admin/exams', [$admin, 'exams'], $admMw);
$router->post('/admin/exams', [$admin, 'createExam'], $admMw);
$router->post('/admin/exams/{id}/status', [$admin, 'updateExamStatus'], $admMw);
$router->post('/admin/marks/import', [$admin, 'importMarksCsv'], $admMw);
$router->post('/admin/users/{id}/reset-password', [$admin, 'resetUserPassword'], $admMw);
$router->post('/admin/users/{id}/email', [$admin, 'updateUserEmail'], $admMw);
$router->get('/admin/settings', [$admin, 'getSettings'], $admMw);
$router->post('/admin/settings', [$admin, 'updateSettings'], $admMw);
$router->get('/admin/audit-logs', [$admin, 'auditLogs'], $admMw);
$router->get('/admin/audit-logs/export', [$admin, 'exportAuditLogs'], $admMw);

// Analytics routes
$analytics = new AnalyticsController();
$router->get('/student/analytics', [$analytics, 'studentAnalytics'], $stuMw);
$router->get('/faculty/analytics', [$analytics, 'facultyAnalytics'], $facMw);
$router->get('/admin/analytics', [$analytics, 'adminAnalytics'], $admMw);

$router->dispatch($request);
