# EPAP REST API Reference (`docs/API.md`)

All endpoints are served under `/api/*` (same-origin). State-changing requests (`POST`, `PUT`, `DELETE`) require `X-CSRF-Token` (obtained from `GET /api/auth/csrf` or `GET /api/auth/me`). Errors return `{ "error": "message" }` with HTTP `400/401/403/404/409/422/429/500`.

## Authentication (`/api/auth/*`)
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/api/auth/csrf` | Public | Returns `{ csrf_token }` |
| `POST` | `/api/auth/login` | Public | `{ identifier, password, role }` → validates credentials, throttles (5/15m), sends OTP. Returns `{ success, maskedEmail, role, csrf_token, devOtp? }` |
| `POST` | `/api/auth/verify-otp` | Pending | `{ otp }` → verifies hashed OTP (max 5 attempts), starts session, returns `{ success, role, must_change_password, redirectTo }` |
| `POST` | `/api/auth/resend-otp` | Pending | 60s cooldown, max 3 resends/session |
| `POST` | `/api/auth/logout` | Any | Destroys session |
| `GET` | `/api/auth/me` | Auth | Current user + `csrf_token` + `must_change_password` |
| `POST` | `/api/auth/change-password` | Auth | `{ current_password, new_password }` |
| `POST` | `/api/auth/forgot-password` | Public | `{ identifier }` → sends reset OTP |
| `POST` | `/api/auth/reset-password` | Public | `{ identifier, otp, new_password }` |

## Student (`/api/student/*`, role=`student`)
- `GET /api/student/profile` — Student + user profile
- `GET /api/student/stats` — `{ sgpa, cgpa, pending_activities, graded_submissions, coding_solved, coding_total }`
- `GET /api/student/activities` — Division-scoped activities with submission status
- `POST /api/student/submit` — `multipart/form-data` (`activity_id`, `file`, `project_url?`, `notes?`). Computes `is_late` server-side.
- `GET /api/student/submissions/{id}/download` — Owner-only file download
- `GET /api/student/results?semester=X` — Published/locked semester marks only
- `GET /api/student/analytics` — Historical SGPA, timeliness, subject radar, explainable prediction
- `GET /api/student/notifications`, `POST /api/student/notifications/read`

## Coding Practice (`/api/practice/*`, role=`student`)
- `GET /api/practice/problems?track=dsa|oop|dbms&difficulty=&q=`
- `GET /api/practice/problems/{id}`
- `POST /api/practice/run` — `{ problem_id, language, code }`
- `POST /api/practice/submit` — `{ problem_id, language, code }`
- `GET /api/practice/my-submissions`

## Faculty (`/api/faculty/*`, role=`faculty`)
- `GET /api/faculty/profile`, `GET /api/faculty/allocations`, `GET /api/faculty/stats`
- `GET /api/faculty/activities`, `POST /api/faculty/activities`, `DELETE /api/faculty/activities/{id}`
- `GET /api/faculty/submissions?activity_id=&status=&is_late=`
- `POST /api/faculty/grade` — `{ submission_id, marks, feedback }`
- `GET /api/faculty/submissions/{id}/download`, `GET /api/faculty/grades/export`
- `GET /api/faculty/analytics`

## Admin / HOD (`/api/admin/*`, role=`admin`)
- `GET /api/admin/stats`, `GET /api/admin/analytics`
- `GET /api/admin/students?q=`, `POST /api/admin/students`
- `POST /api/admin/students/import` (`multipart/form-data` CSV) → per-row `{ created, skipped, errors, rows }`
- `GET /api/admin/students/template` (CSV template download)
- `GET /api/admin/faculty`, `GET /api/admin/subjects`
- `GET /api/admin/allocations`, `POST /api/admin/allocations`, `DELETE /api/admin/allocations/{id}`
- `GET /api/admin/exams`, `POST /api/admin/exams/{id}/status` (`draft|under_moderation|published|locked`)
- `POST /api/admin/marks/import` (CSV)
- `GET /api/admin/audit-logs?action=&role=`, `GET /api/admin/audit-logs/export`
