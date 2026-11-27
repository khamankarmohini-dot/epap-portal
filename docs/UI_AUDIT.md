# EPAP UI Hardcoded-Value Audit
_Generated: 2026-11-26 | Phase 1 of the data-driven refactor_

---

## Summary

Every HTML page and JS file was read. The table below lists every hardcoded value
found and what it must become after the fix.

**Legend — Source column:**
- `HC` = Hardcoded literal (the bug)
- `API` = Already driven by an API call
- `SETTINGS` = Must come from `/api/settings/public`
- `SUMMARY` = Must come from role-specific summary endpoint

---

## 1. Student Pages

### 1a. Sidebar (identical across all 5 student pages)

| Element | Current value | Required source |
|---|---|---|
| Academic year line | `2025–2026 • Semester V` (HC) | `SUMMARY` → `session.academic_year` + `session.current_semester` |
| Class badge | `TE-IT Division A` (HC) | `SUMMARY` → `student.year` + `student.division` |
| "My Activities" badge | `2 Due` (HC) | `SUMMARY` → `pending_activities` |
| Practice badge | `100+ Qs` (HC) | `SUMMARY` → `coding_total` (hide if 0) |

### 1b. Header avatar (identical across all 5 student pages)

| Element | Current value | Required source |
|---|---|---|
| Initials circle | `RS` (HC) | `updateNavbarUser(me)` already sets this — HTML fallback must be blank |
| Name label | `Student` (HC) | `updateNavbarUser(me)` |
| Identifier label | `STUDENT` (HC) | `updateNavbarUser(me)` |

### 1c. student-dashboard.html

| Element | Current value | Required source |
|---|---|---|
| Academic Year badge | `Academic Year 2025–26` (HC) | `SETTINGS` `academic_year` via `data-setting` |
| Greeting name | `Hello, Student!` (HC) | JS already sets from `profile.full_name` |
| Enrolment line | `Third Year Information Technology (TE-IT) — Div A … Roll No: 42` (HC) | JS already sets from `profile` |
| SGPA stat card | `8.92` (HC fallback) | JS sets from `stats.sgpa` |
| CGPA stat card | `8.64` (HC fallback) | JS sets from `stats.cgpa` |
| Pending Activities card | `2` (HC fallback) | JS sets from `stats.pending_activities` |
| Coding Qs card | `34/100` (HC fallback) | JS sets from `stats.coding_solved/coding_total` |
| "View All (5)" button | `5` hardcoded | Must equal `pending_activities` from SUMMARY |
| Deadlines sub-label | `Assignments assigned by IT Department faculty` (HC) | Use `data-setting="department_name"` + ` faculty assignments` |
| SGPA stat label | `Current SGPA (Sem 5)` (HC) | Show `Current SGPA (Sem ${student.semester})` |
| Deadline list items | 3 hardcoded rows (DBMS Lab 4, WT Milestone 2, CN Socket) | JS replaces from `/api/student/activities` ✓ but static HTML shows first |
| Recent Marks items | 2 hardcoded (TOC, SE) | JS replaces from activities ✓ |
| Chart subtitle | `Student SGPA vs Zeal IT Department Benchmark` (HC) | Use settings-driven department name |
| Radar subtitle | `Evaluated across SPPU IT Curriculum` (HC) | Remove SPPU reference |
| Notification items | 2 hardcoded items | JS replaces from `/api/student/notifications` ✓ |
| Notification badge | `3` (HC) | JS sets from `notifs.unread` ✓ |

### 1d. student-activities.html

| Element | Current value | Required source |
|---|---|---|
| Filter dropdown | `All Activities (4)`, `Action Required (2)`, etc. (HC counts) | Update counts dynamically after `loadActivities()` |
| Activity cards | 4 hardcoded cards (DBMS, WT, CN, TOC) | JS replaces from API ✓ but static HTML still shows until load |

### 1e. student-results.html

| Element | Current value | Required source |
|---|---|---|
| Semester selector | `Semester V (Current)`, `IV (SGPA: 8.76)`, etc. (HC) | JS rebuilds from published marks ✓ |
| Student name | `Student` (HC) | JS sets from `studentProfile.full_name` ✓ |
| ZPRN | `STUDENT` (HC) | JS sets from `studentProfile.zprn` ✓ |
| Seat number | `T23104921` (HC) | JS sets from `studentProfile.seat_number` ✓ |
| Academic Year+Sem | `2025–26 • SEMESTER V` (HC) | JS sets from `studentProfile.semester` (partial — year still HC) |
| Marks table | 5 hardcoded rows (314441–314445) | JS replaces from `/api/student/results` ✓ |
| Performance widgets | `22/22`, `8.92`, `8.64`, `Distinction` (HC) | JS replaces ✓ |
| Marksheet header | `T.E. (INFORMATION TECHNOLOGY)` (HC) | Should come from settings/student.year |

### 1f. student-analytics.html

| Element | Current value | Required source |
|---|---|---|
| Dataset badge text | `5 Completed Semesters + Sem 5 In-Sem` (HC) | JS updates prediction box ✓ |
| Projected SGPA | `Projected Semester VI SGPA: 9.08 ± 0.22` (HC) | JS updates from API ✓ |
| Forecast description | `83.7%`, `85.7%` (HC) | JS updates from API ✓ |
| Confidence score | `91.4%` (HC) | JS updates from API ✓ |
| Timeliness summary | `85.7% On-Time` (HC) | JS updates ✓ |
| Recommendations | 2 hardcoded items (DBMS strength, TOC gap) | JS updates from `pred.recommendations` ✓ |

### 1g. student-practice.html

| Element | Current value | Required source |
|---|---|---|
| Subject label | `100 Qs / Subject` (HC) | Show real count from `/api/student/stats` → `coding_total` |
| Solved counter | `1 / 100 Solved` (HC) | JS must set from `stats.coding_solved` / `stats.coding_total` |
| Progress bar | `1%` width (HC) | JS must compute from solved/total |
| `epapRequireAuth` | Missing! (page skips auth check) | Add `epapRequireAuth('student')` call |

---

## 2. Faculty Pages

### 2a. Sidebar (identical across faculty pages)

| Element | Current value | Required source |
|---|---|---|
| Role label | `Assistant Professor (IT)` (HC) | `SUMMARY` → `designation` from faculty profile |
| Class badge | `TE-IT Div A • SE-IT Div B` (HC) | `SUMMARY` → `allocations` list |
| "Manage Activities" badge | `14 Pending` (HC) | `SUMMARY` → `pending_evaluations` |
| Allocated Courses section | `DBMS (314441)`, `OOP Lab (314447)` (HC links) | `SUMMARY` → `allocations[]` dynamically rendered |

### 2b. Header avatar

| Element | Current value | Required source |
|---|---|---|
| Initials | `SP` (HC) | `updateNavbarUser(me)` |
| Name | `Faculty` (HC) | `updateNavbarUser(me)` |
| Identifier | `FACULTY` (HC) | `updateNavbarUser(me)` |
| Notification badge | `5` (HC) | No faculty notification endpoint yet — hide or show 0 |

### 2c. faculty-dashboard.html

| Element | Current value | Required source |
|---|---|---|
| Greeting | `Welcome, Faculty` (HC) | JS sets from `profile.full_name` ✓ |
| Assigned description | `DBMS & OOP Lab` (HC) | Should come from allocations |
| `Grading Queue (14)` button | `14` (HC) | JS must set from `stats.pending_evaluations` |
| `Full Queue (14)` link | `14` (HC) | Same as above |
| Stat: Assigned Students | `140` (HC) | JS sets from `stats.total_students` ✓ |
| Stat: Pending Evaluations | `14` (HC) | JS sets from `stats.pending_evaluations` ✓ |
| Stat: Graded | `126` (HC) | JS sets from `stats.graded_submissions` ✓ |
| Stat: Class Average | `79.2%` (HC) | JS sets from `stats.class_avg` ✓ |
| Submissions table | Loading state only | JS replaces from `/api/faculty/submissions` ✓ |
| Chart subtitle | `TE-IT Division A vs Division B across all SPPU In-Sem` (HC) | Use dynamic allocation labels |
| Notification items | 2 hardcoded (DBMS Lab 4 submissions) | No real faculty notification endpoint |
| Hardcoded feedback in modal | `Well-structured PL/SQL trigger...` (HC) | Modal always pre-fills this — must clear on open |

### 2d. faculty-activities.html

| Element | Current value | Required source |
|---|---|---|
| Sidebar | Same as faculty-dashboard sidebar (HC) | Same fix |
| Academic session badge | `Academic Session 2025–26` (HC) | `data-setting="academic_year"` |
| Activities table | 3 hardcoded rows | JS replaces from `/api/faculty/activities` ✓ |

---

## 3. Admin Pages

### 3a. admin-dashboard.html

| Element | Current value | Required source |
|---|---|---|
| Initials | `AD` (HC) | `updateNavbarUser(me)` ✓ (JS sets) |
| Stat cards | `0` initial values | JS fills from `/api/admin/stats` ✓ |
| Audit log table | Static loading state | JS replaces ✓ |

### 3b. admin-management.html

| Element | Current value | Required source |
|---|---|---|
| Allocation modal class options | `Third Year (TE-IT) - Division A`, etc. (HC) | Acceptable as fixed enum values — document in DECISIONS |
| `alert()`/`confirm()` calls | Native browser dialogs | Replace with Bootstrap modal confirm dialog |

---

## 4. Cross-Cutting Issues

| Issue | Location | Fix |
|---|---|---|
| `Cache-Control` missing | All `/api/*` responses | Add `no-store` in `Response::json()` |
| `fetch` without `cache: 'no-store'` | `api.js:epapFetch` | Add `cache: 'no-store'` to all GET fetches |
| Auto-refresh missing | All pages | `shell.js` with `visibilitychange`, 60 s interval, focus |
| Duplicate `escapeHtml` | `api.js` + `main.js` both define it | Remove from `main.js`, keep in `api.js` only |
| Year/semester data inconsistency | Student "Mohini Khamankar" is SE-IT but semester 5 | Add admin warning list; add server-side validation |
| No loading skeletons | Stat widgets show HC numbers until JS runs | Add `.placeholder-glow` skeleton class before JS runs |
| `initCodingArena()` without auth | `student-practice.html` | Add `epapRequireAuth('student')` before arena init |

---

## 5. Settings Keys Required

The following `settings` keys must exist (add defaults if missing):

| Key | Default | Used by |
|---|---|---|
| `institution_name` | `Zeal College of Engineering and Research, Narhe` | Header, footer |
| `department_name` | `Information Technology` | Sidebar, dashboard subtext |
| `short_logo_text` | `EPAP` | Brand badge |
| `academic_year` | `2025–2026` | Student sidebar, results page |
| `current_semester` | `5` | Student sidebar |
| `benchmark_label` | `Department Benchmark` | Analytics chart subtitle |
| `curriculum_label` | `University Curriculum` | Radar chart subtitle |
| `support_email` | `'' ` | Footer |

---

## 6. Year/Semester Validation Rule

| Year | Valid Semesters |
|---|---|
| `FE-IT` | 1, 2 |
| `SE-IT` | 3, 4 |
| `TE-IT` | 5, 6 |
| `BE-IT` | 7, 8 |

Existing student "Mohini Khamankar" appears stored as `SE-IT` with semester 5 — this violates the rule.
Admin must correct via the warning panel added to `admin-dashboard.html`.

---

## 7. Single Source of Truth for Counts

The count "pending activities" for a student is defined **once** as:

> Activities assigned to the student's `year` + `division` where NO submission exists
> AND (`deadline > NOW()` OR `allow_late = 1`)

This one definition feeds:
1. Sidebar badge (`pending_activities`)
2. Dashboard stat card (`Pending Activities`)
3. "View All (N)" button text
4. All filter counts on `student-activities.html`

Same value returned by `/api/student/summary`.
