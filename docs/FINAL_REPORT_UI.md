# EPAP Data-Driven UI & Live-Refresh Final Report
_Date: 2026-11-26_

---

## 1. Summary of Changes: Hardcoded vs. Dynamic

| Page / Component | Element | Old Source | New Source |
|---|---|---|---|
| **All Role Headers** | Avatar initials, full name, ID | Hardcoded `RS`, `SP`, `AD`, `Student`, `Faculty`, `Administrator` | `EPAP_SHELL` via `/api/{role}/summary` + `EPAP_API.updateNavbarUser()` |
| **All Role Headers & Footers** | Institution & department names | Hardcoded `Zeal College...`, `Information Technology Dept.` | `/api/settings/public` & summary (`data-setting="institution_name"`, `department_name`) |
| **Student Sidebar (all 5 pages)** | Session & semester | Hardcoded `2025–2026 • Semester V` | `/api/student/summary` (`session.academic_year`, `student.semester`) |
| **Student Sidebar (all 5 pages)** | Class & division badge | Hardcoded `TE-IT Division A` | `/api/student/summary` (`student.year`, `student.division`) |
| **Student Sidebar (all 5 pages)** | `My Activities` badge | Hardcoded `2 Due` | `/api/student/summary` (`pending_activities`; hidden if `0`) |
| **Student Sidebar (all 5 pages)** | `LeetCode Practice` badge | Hardcoded `100+ Qs` | `/api/student/summary` (`coding_total`; hidden if `0`) |
| **student-dashboard.html** | KPI cards (`SGPA`, `CGPA`, `Pending`, `Coding`) | Hardcoded `8.92`, `8.64`, `2`, `34/100`, `Sem 5` | `/api/student/summary` (with `—` initial state) |
| **student-dashboard.html** | `View All (5)` button | Hardcoded `5` | `/api/student/summary` (`pending_activities`) |
| **student-dashboard.html** | Upcoming deadlines & recent marks | Hardcoded 3 activity rows + 2 mark cards | `/api/student/activities` (loading state until fetched) |
| **student-dashboard.html** | Chart subtitles | Hardcoded `Zeal IT Department Benchmark`, `SPPU IT Curriculum` | `settings` (`benchmark_label`, `curriculum_label`) |
| **student-activities.html** | Activity cards & filter counts | 4 hardcoded cards; `All (4)`, `Action Required (2)`, etc. | `/api/student/activities` + dynamic filter counts & status chips |
| **student-results.html** | Seat no, marks table, summary, header | Hardcoded `T23104921`, 5 static rows, `22/22`, `8.92` | `/api/student/profile` + `/api/student/results` (published only) |
| **student-analytics.html** | Forecast SGPA, confidence, recs | Hardcoded `9.08 ± 0.22`, `91.4%`, `85.7%`, static recs | `/api/student/analytics` + chart empty-state overlays |
| **student-practice.html** | Problem count, solved/total, auth | Hardcoded `100 Qs / Subject`, `1 / 100 Solved`, `1%`, no auth guard | `epapRequireAuth('student')` + `/api/student/summary` |
| **faculty-dashboard.html** | Sidebar role, classes, pending badge, courses | Hardcoded `Assistant Professor (IT)`, `TE-IT Div A • SE-IT Div B`, `14 Pending`, `DBMS (314441)`, `OOP Lab (314447)` | `/api/faculty/summary` (`designation`, `class_badges`, `pending_evaluations`, `allocations`) |
| **faculty-dashboard.html** | Stat cards, queue counts, modal text | Hardcoded `140`, `14`, `126`, `79.2%`, `Grading Queue (14)`, prefilled feedback | `/api/faculty/summary` + `/api/faculty/submissions` (modal cleared on open) |
| **faculty-activities.html** | Activities table, session badge, analytics link | 3 hardcoded rows, `2025–26`, broken link to `faculty-dashboard.html` | `/api/faculty/activities`, `data-setting="academic_year"`, fixed `faculty-analytics.html` |
| **faculty-analytics.html** | Sidebar & institution branding | Static sidebar without allocations/pending badge | `EPAP_SHELL` + `/api/faculty/summary` + `/api/faculty/analytics` |
| **admin-dashboard.html** | Year/semester mismatch warning | Not present | `#inconsistentWarningCard` via `/api/admin/students/inconsistent` |
| **admin-management.html** | Dialogs, student year/sem, settings | `alert()`/`confirm()`/`prompt()`, missing `FE-IT`/semester input | Bootstrap modals (`EPAP_UI.confirmDialog`), semester input, `benchmark_label`/`curriculum_label` |

---

## 2. Endpoints Added

- `GET /api/student/summary` — Single source of truth for student shell counters, profile summary, settings labels, and `server_now`.
- `GET /api/faculty/summary` — Single source of truth for faculty counters, active allocations, designation, and `server_now`.
- `GET /api/admin/summary` — Department KPIs, inconsistent student count, admin user info, and `server_now`.
- `GET /api/admin/students/inconsistent` — Lists students whose `year` and `semester` disagree.
- `POST /api/admin/students/{id}/fix-year-semester` — Validates and updates a student's `year` and `semester`.

---

## 3. Definition of Counts

- **Pending Activities (`pending_activities` / `due_activities`)**: Activities matching the student's `(year, division)` where no row exists in `submissions` for that student AND `(deadline > NOW() OR allow_late = 1)`.
- **Faculty Pending Evaluations (`pending_evaluations`)**: Submissions for the faculty's activities with `status = 'submitted'`.

---

## 4. Files Changed

- **Backend PHP**:
  - `app/Core/Response.php` (added `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` & `Pragma: no-cache`)
  - `app/Core/Settings.php` (added `benchmark_label`, `curriculum_label`)
  - `app/Controllers/StudentController.php` (`summary()`, unified `stats()` pending definition)
  - `app/Controllers/FacultyController.php` (`summary()`)
  - `app/Controllers/AdminController.php` (`summary()`, `inconsistentStudents()`, `fixStudentYearSemester()`, year/semester validation in `createStudent()` & `importStudentsCsv()`)
  - `api/index.php` (registered new routes)
- **Frontend JS & CSS**:
  - `assets/js/shell.js` (new shared shell, live auto-refresh, visibility/focus/backoff, refresh indicator)
  - `assets/js/api.js` (`cache: 'no-store'`, `deriveInitials()`, `EPAP_SHELL.stop()` on 401)
  - `assets/js/main.js` (removed duplicate `escapeHtml`, server-time countdowns, `EPAP_UI.confirmDialog` & `setButtonLoading`)
  - `assets/js/analytics.js` (`toggleChartEmpty()` "Not enough data yet" state)
  - `assets/css/style.css` (360px responsive adjustments, focus outlines, truncation utility)
- **HTML Pages**:
  - `student-dashboard.html`, `student-activities.html`, `student-results.html`, `student-analytics.html`, `student-practice.html`
  - `faculty-dashboard.html`, `faculty-activities.html`, `faculty-analytics.html`
  - `admin-dashboard.html`, `admin-management.html`
- **Tests & Docs**:
  - `tests/smoke.php`, `docs/UI_AUDIT.md`, `docs/DECISIONS.md`, `docs/TESTING.md`, `docs/FINAL_REPORT_UI.md`

---

## 5. Manual Data Fix Required (Existing Database)

A read-only check of the live database found **1 student record** with an inconsistent `year` / `semester`:

- **Student**: `Mohini Khamankar` (`id = 1`, `zprn = 126UIT2017`, `division = A`)
- **Current values**: `year = 'SE-IT'`, `semester = 5`

You can fix this directly from the new **Year / Semester Mismatch Detected** warning card on `admin-dashboard.html`, or by running one of the following SQL statements:

```sql
-- If Mohini is in Third Year (Semester 5):
UPDATE students SET year = 'TE-IT', semester = 5 WHERE id = 1;

-- Or if Mohini is in Second Year (Semester 3):
UPDATE students SET year = 'SE-IT', semester = 3 WHERE id = 1;
```

---

## 6. What Could Not Be Verified

- Full destructive execution of `tests/smoke.php` was not run against the live database so existing production data and SMTP configuration (`smtp.gmail.com:587`) remain untouched.
- Interactive browser rendering, console error inspection, and 360px mobile viewport layout were not run in a live headless browser in this terminal session.
