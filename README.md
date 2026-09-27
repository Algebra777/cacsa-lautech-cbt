# Alge CBT

Alge CBT is a PHP and vanilla JavaScript assessment application. The student portal, admin workspace, and REST API share the same JSON data store. No Node build step or external UI framework is required.

## Run locally

1. Start Apache in XAMPP. The project needs PHP 8.1 or later and write access to this folder for the JSON data store.
2. Open `http://localhost/CBT-Alge/` in a browser. Opening `index.html` with a `file://` URL will not connect to `api.php`.
3. Sign in through **Admin panel**. Configure administrator credentials in the server environment with `CBT_ADMIN_EMAIL` and a `CBT_ADMIN_PASSWORD_HASH` produced by PHP's `password_hash()` before deployment. The development bootstrap account in `api.php` is only for local setup.

`api.php` persists students, exams, questions, access passwords, exam sessions, results, and grading settings in `cbt-data.json`. Back up that file before upgrading or moving a live installation. Restrict direct web access to the data file and serve the application over HTTPS outside localhost.

## Admin workflow

- Register and manage students, then create an assessment with its duration, question count, course unit, optional session/semester, and availability window.
- Open an assessment's question bank to add or edit single-answer and multiple-answer questions. Pick the correct option or options in the form; question availability can be changed without deleting it.
- Generate a separate access password for each student and assessment.
- Use Results for submissions and the student's result sheet. The result sheet groups courses by session, shows semester GPA and cumulative CGPA, and can be exported as CSV.
- Edit grade boundaries and points in Settings. Saved results are recalculated when the grading scale or a course unit changes.

## Student workflow

Students select an available assessment, enter their matric number and issued password, review the duration and question count, then start. The server records the exam deadline. Answers and flags are saved as the student works, and the student confirms manual submission. Results distinguish manual from timed submission.

The question editor includes a mathematics keyboard for powers, roots, fractions, derivatives, integrals, Greek letters, and common operators. For example, `10x^(2)` is previewed as an exponent and rendered for students.

## Unattended timeout finalization

The browser asks the backend to submit as soon as its server-issued deadline reaches zero. For a local XAMPP installation, add a Windows Task Scheduler task every minute for truly unattended expiry processing (for example, when a browser is closed): program `C:\xampp\php\php.exe`, argument `C:\xampp\htdocs\CBT-Alge\expire-sessions.php`. The helper is CLI-only and cannot be opened from the web.

## Data and security

This is a single-server PHP/JSON application. Keep `cbt-data.json` private, writable by PHP, and included in backups. Set a unique admin password hash before any nonlocal use. Exam access passwords and admin sessions are separate; administrators should sign out on shared computers. Repeated failed sign-ins are temporarily throttled per client to reduce password guessing.
