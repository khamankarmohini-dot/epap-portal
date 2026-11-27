# EPAP Codebase Audit (`docs/AUDIT.md`)

**Date:** 2026-11-25  
**Scope:** Full audit of existing HTML/Bootstrap/JS frontend and partial Node.js/Express/MySQL backend prior to PHP 8.1+ conversion.

---

## 1. API Contract Table (Existing Frontend `fetch()` Calls)

| Page / File | Method & URL | Request Body / Headers | Response Fields Consumed by Frontend |
|---|---|---|---|
| `assets/js/main.js` | `POST /api/auth/logout` | None | Ignored (redirects to `/login.html`) |
| `login.html` | `POST /api/auth/login` | JSON `{ identifier, password, role }` | `error` (on !ok), `maskedEmail`, `devOtp` (optional) |
| `verify-otp.html` | `POST /api/auth/verify-otp` | JSON `{ otp }` | `error` (on !ok), `redirectTo` |
| `student-dashboard.html` | `GET /api/student/profile` | Session cookie | `full_name`, `year`, `division`, `zprn`, `roll_no` |
| `student-dashboard.html` | `GET /api/student/stats` | Session cookie | `sgpa`, `cgpa`, `pending_activities` |
| `student-dashboard.html` | `GET /api/student/activities` | Session cookie | Array of `{ id, title, subject_code, faculty_name, max_marks, deadline, submission_id, sub_status, marks, feedback }` |
| `student-activities.html` | `GET /api/student/activities` | Session cookie | Array of `{ id, title, description, subject_code, faculty_name, max_marks, deadline, submission_id, sub_status, marks, feedback, file_name }` |
| `student-activities.html` | `POST /api/student/submit` | JSON `{ activity_id, file_name }` *(Note: currently fake, no actual file uploaded; will upgrade to `multipart/form-data`)* | `error` (on !ok), `is_late` |
| `student-results.html` | `GET /api/student/profile` | Session cookie | `current_sgpa` (plus `full_name`, `zprn`, `cgpa`, `semester`, `division`, `year`) |
| `student-results.html` | `GET /api/student/results` | Session cookie | Array of `{ code, name, in_sem, end_sem, practical, semester, sgpa }` |
| `faculty-dashboard.html` | `GET /api/faculty/profile` | Session cookie | `full_name` |
| `faculty-dashboard.html` | `GET /api/faculty/stats` | Session cookie | `total_students`, `pending_evaluations`, `graded_submissions`, `class_avg` |
| `faculty-dashboard.html` | `GET /api/faculty/submissions` | Session cookie | Array of `{ id, student_name, zprn, division, subject_code, activity_title, submitted_at, is_late, status, marks, max_marks, file_name }` |
| `faculty-dashboard.html` | `POST /api/faculty/grade` | JSON `{ submission_id, marks, feedback }` | `error` (on !ok), `success` |
| `faculty-activities.html` | `GET /api/faculty/activities` | Session cookie | Array of `{ id, title, max_marks, subject_code, division, deadline, submission_count }` |
| `faculty-activities.html` | `POST /api/faculty/activities` | JSON `{ subject_id, title, description, max_marks, deadline, division }` | `error` (on !ok), `success`, `id` |
| `admin-dashboard.html` | `GET /api/admin/stats` | Session cookie | `total_students`, `total_faculty`, `total_submissions`, `pending_grading` |
| `admin-dashboard.html` | `GET /api/admin/audit-logs` | Session cookie | Array of `{ created_at, full_name, role, action, detail, ip }` |

---

## 2. Static, Hardcoded, or Simulated Pages & Sections

1. **`admin-management.html` (100% static/simulated):**
   - Faculty Course & Division Allocation Matrix has 3 hardcoded rows; delete buttons do nothing; "New Allocation" modal (`handleAllocation`) just calls `alert()`.
   - Bulk Student Enrollment CSV dropzone has no upload handler, no drag-and-drop JS, no results table, and "Download Sample CSV Template" links to `#`.
   - Semester Examination Result Moderation & Release has 2 hardcoded rows with `alert()` buttons.
2. **`student-analytics.html` & `assets/js/analytics.js` (100% hardcoded):**
   - Forecast SGPA (`9.08 ± 0.22`), confidence (`91.4%`), on-time rate (`85.7%`), and recommendations are hardcoded in HTML.
   - `renderSemesterProgressChart`, `renderSubmissionTimelinessChart`, `renderSubjectRadarChart`, `renderPredictiveForecastChart`, `renderClassPerformanceBar` all use hardcoded arrays.
3. **`student-practice.html` & `assets/js/code-practice.js` (100% client-side simulated):**
   - `QUESTION_BANK` is hardcoded in JS with 6 real problems + 96 generated dummy problems.
   - `runCode()` and `submitSolution()` use `setTimeout()` to fake Judge0 compilation and always report "Accepted! All 25/25 Test Cases Passed!".
   - Progress is stored in `localStorage` (`zeal_epap_solved_questions`).
   - Search input and difficulty filter buttons (`All`, `Easy`, `Med`, `Hard`) in `student-practice.html` have no event listeners.
4. **`faculty-dashboard.html` & `routes/faculty.js`:**
   - `GET /api/faculty/stats` returns `totalStudents.cnt || 140` and hardcoded `class_avg: 79.2`.
   - Sidebar links to `faculty-analytics.html` which does **not exist** (404).
   - Topbar notifications, navbar profile name/ID/avatar, and sidebar role/courses are hardcoded to `Prof. S. N. Patil`.
   - "Download Submission.zip" in grading modal links to `#`.
   - `faculty-activities.html`: "Export All Marks (CSV)" button has no handler; `handleCreateActivity` hardcodes `subject_id` mapping (`oop_b ? 6 : 1`); "Graded" column shows `—`; file attachment input is ignored.
5. **`student-dashboard.html`:**
   - Topbar notifications (badge `3`, 2 items) are hardcoded.
   - Coding Qs Solved KPI (`34/100`) is never updated from API.
   - Sidebar "2 Due" badge and "Semester V / TE-IT Division A" are hardcoded.
   - Profile dropdown links ("My Academic Profile", "Change Password") link to `#`.
6. **`student-results.html`:**
   - Semester selector `<select>` has no `change` handler and hardcoded SGPA options.
   - Metadata grid (`ROHAN K. SHARMA`, `T23104921`, `2025-26 • SEMESTER V`), summary cards (`22/22`, `8.92`, `8.64`, `Distinction`), and initial table rows are hardcoded.
   - Shows all `semester_marks` regardless of whether results are published by HOD.
7. **`admin-dashboard.html`:**
   - Topbar profile, greeting, and "Download Audit Log" button are static/non-functional; "Admin Security Settings" and "Audit Trail Logs" dropdown links point to `#`.
8. **`verify-otp.html`:**
   - `resendOtp()` is a stub showing `"Please go back to login and request a new OTP."` instead of calling an API endpoint.

---

## 3. Bugs & Security Vulnerabilities Found

### Backend (Node.js)
1. **Weak OTP generation:** `Math.floor(100000 + Math.random() * 900000)` uses non-CSPRNG `Math.random()`.
2. **Plaintext OTP storage & no attempt limit:** `otp_tokens.otp` is stored in plaintext, previous OTPs are not invalidated on new login, and there is no max-attempt lockout on `/api/auth/verify-otp`.
3. **No login rate limiting / brute-force protection:** Unlimited login attempts allowed.
4. **No CSRF protection:** `cors({ origin: true, credentials: true })` + session cookie without `SameSite` or CSRF tokens.
5. **Hardcoded secrets:** `db.js` and `seed.js` hardcode DB password `'kaira'`; `server.js` hardcodes session secret `'epap_zeal_it_secret_2026'`.
6. **Error leakage:** `res.status(500).json({ error: err.message })` leaks SQL/database errors directly to clients.
7. **Missing authorization checks:**
   - `POST /api/faculty/grade` updates any `submission_id` without checking if the activity belongs to the logged-in faculty!
   - `POST /api/student/submit` does not verify that `activity_id` exists or belongs to the student's division, and trusts `file_name` from JSON body (no actual file upload or validation).
   - `POST /api/faculty/activities` does not verify the faculty is allocated to that subject/division.
   - `GET /api/student/results` returns all marks without checking if the exam session is published.
8. **SQL / Schema issues:**
   - `faculty.subjects` uses loose JSON array instead of a proper `faculty_allocations` table.
   - `semester_marks` lacks a unique constraint `(student_id, subject_id, semester)` — re-running `seed.js` inserts duplicate marks rows!
   - `submissions` has `file_name` only, no `file_path`, `original_name`, `file_size`, `mime_type`.
9. **Stray / inconsistent files:** Unused SQLite `database/schema.js`, stray `query` file in root.

### Frontend
1. **Stored XSS via `innerHTML`:** `student-dashboard.html`, `student-activities.html`, `student-results.html`, `faculty-dashboard.html`, `faculty-activities.html`, `admin-dashboard.html`, and `main.js` (`showToast`) interpolate API strings (`act.title`, `act.feedback`, `s.student_name`, `l.detail`, etc.) directly into `innerHTML` without HTML escaping.
2. **Inline JS injection risk:** `onclick="openGrading(..., '${s.student_name.replace(/'/g, "\\'")}')"` breaks on backslashes/quotes/newlines and is XSS-prone.
3. **Bug in `student-activities.html`:** Static HTML buttons pass 3 args to `prepareSubmissionModal(title, subject, maxMarks)` while the JS function expects 4 args `(id, title, subject, maxMarks)`.
4. **Bug in `student-dashboard.html`:** `document.querySelector('.card-body.p-3')` is fragile and relies on DOM order; `profile.year` is interpolated into `"${profile.year} Information Technology (TE-IT)"` duplicating `"TE-IT"`.
5. **Missing auth/role guard:** Protected pages only redirect on `401`, not when a student visits `faculty-dashboard.html` (401/403 from faculty API isn't handled consistently, and role mismatch isn't redirected to the user's own dashboard).
6. **Missing pages:** `faculty-analytics.html` is linked from `faculty-dashboard.html` sidebar; profile/change-password links point to `#`.
