# Alge CBT

A browser-ready CBT application with a PHP REST backend and JSON-backed local persistence.

## Run locally

Open `index.html` directly in a browser, or serve the folder with XAMPP/Apache at:

`http://localhost/CBT-Alge/`

## Included in this prototype

- Active exam selection with scheduled exam cards
- Exam-specific student login and pre-exam confirmation
- Timed exam interface with autosave-style local state, question map, flagging, and submission confirmation
- Admin dashboard with overview stats, recent submissions, live activity, and navigation stubs for students, exams, questions, results, and settings
- Responsive layouts for desktop, tablet, and mobile

The API lives in `api.php` and writes persistent records to `cbt-data.json` on first use. It supports administrator login, students, exams, questions, access-password generation, server-timed exam sessions, autosave, flags, submissions, results, and GPA/CGPA computation.

## GPA and CGPA

- Set each course's unit (1–6) and optional session/semester in **Exam management**.
- Edit the persisted five-point grading scale in **Settings**. Saving it recalculates all completed results.
- Open a student's **Results** from the Students list or Results table for their course grades, semester GPA, cumulative CGPA, and CSV export.

## Administrator access

- Email: `adepojutimothy001@gmail.com`
- Password: `admin123`

Use the **Admin panel** link to register a student, create or activate an exam, add questions, then generate that student's per-exam password. The student can then use their matric number and generated password on the student portal.
