# Berevion

For production hosting, operations, queue-worker scheduling, backup/restore drills, monitoring, and capacity validation, see [the production deployment guide](docs/production-deployment-guide.md). That guide is documentation only and does not alter the local XAMPP setup.

Berevion is a PHP and vanilla JavaScript, multi-institution assessment platform. It uses MySQL for live application data; CACSA LAUTECH is its first tenant and remains independently branded at its tenant URL. No Node build step or external UI framework is required.

## Run locally

1. Start Apache and MySQL in XAMPP. The project needs PHP 8.1 or later and MySQL credentials in the server-only `.env` file.
2. Open `http://localhost/BEREVION/` for the Berevion platform, or `http://localhost/BEREVION/i/cacsa-lautech/` for CACSA LAUTECH. Opening `index.html` with a `file://` URL will not connect to `api.php`.
3. The platform Super Admin signs in from the Berevion landing page. Institution Admins sign in from their institution-specific path.

`api.php` persists students, exams, questions, access passwords, exam sessions, results, and grading settings in MySQL with an institution boundary on every tenant record. `cbt-data.json` remains a read-only historical archive; do not expose it publicly. Serve the application over HTTPS outside localhost.

## Admin workflow

- Register and manage students, then create an assessment with its duration, question count, course unit, optional session/semester, and availability window.
- Open an assessment's question bank to add or edit single-answer and multiple-answer questions. Pick the correct option or options in the form; question availability can be changed without deleting it.
- Generate a separate access password for each student and assessment.
- Use Results for submissions and the student's result sheet. The result sheet groups courses by session, shows semester GPA and cumulative CGPA, and can be exported as CSV.
- Edit grade boundaries and points in Settings. Saved results are recalculated when the grading scale or a course unit changes.
- Use **Audit log** for a live monitor of in-progress and locked sessions, or the full system audit trail. The monitor refreshes every five seconds; its filters support date range, actor, event type, and course.
- Use **Roles** (Superadmin only) to assign Academic Coordinator and Assistant Academic Coordinator accounts. The three fixed roles are Superadmin, Academic Coordinator, and Assistant Academic Coordinator; role limits and permitted workspaces for the two coordinator roles can be adjusted without granting access to Roles itself. Administrator passwords are stored as password hashes, and accounts can be disabled or reactivated.
- Use **Users** (Superadmin only) for the administrator account directory. It shows the protected bootstrap Superadmin and every coordinator account, including role, verification, account status, and last sign-in. Coordinators can be added, assigned a role, disabled, or reactivated there.
- Every signed-in administrator has an account menu with **Account Settings**. Profile changes store the name and optional phone number; changing the sign-in email sends a six-digit code to the new address before it takes effect. Security changes require the current password and revoke other sessions. Privacy offers a one-click sign-out from other devices. If the bootstrap Superadmin email or password is set through `CBT_ADMIN_EMAIL` or `CBT_ADMIN_PASSWORD_HASH`, those specific values remain server-managed and cannot be replaced from the browser.
- People who need administrator access can choose **Request an account** from the administrator sign-in page. Their request remains unable to sign in until the Superadmin reviews it in **Pending Approval**, assigns Academic Coordinator or Assistant Academic Coordinator, and Gmail SMTP accepts the approval email. The request endpoint is rate-limited, stores only a password hash, and does not allow requests for the reserved Superadmin account.
- Use **Newsletter** to manage subscribers and send updates. Every registered student's validated email is automatically stored as an active subscriber, while additional recipients can be added manually. The sender is `cacsalautech001@gmail.com` and delivery uses authenticated Gmail SMTP. To enable it, copy `.env.example` to `.env`, enable 2-Step Verification for that Gmail account, create a Google App Password, and set `CBT_NEWSLETTER_GMAIL_APP_PASSWORD` to it. The `.env` file is ignored by Git and blocked from web access. The application records Gmail SMTP acceptance/failed handoff results; acceptance is not an inbox-delivery receipt.
- Newsletter messages include standard date, MIME, reply-to, and unsubscribe headers. Recipient mail providers still control spam classification; for the best long-term deliverability, use a verified custom sending domain with SPF, DKIM, and DMARC rather than relying solely on a consumer Gmail address.

## Student workflow

Students select an available assessment, enter their matric number and issued password, review the duration and question count, then start. The server records the exam deadline. Answers and flags are saved as the student works, and the student confirms manual submission. Results distinguish manual from timed submission.

The question editor includes a mathematics keyboard for powers, roots, fractions, derivatives, integrals, Greek letters, and common operators. For example, `10x^(2)` is previewed as an exponent and rendered for students.

## Exam-integrity signals

While an exam is active, the browser records tab switches, window blur, right-click, copy, paste, and a best-effort DevTools-size signal. Administrators choose whether each signal is logged, warns the student, or locks the session; warning signals default to locking on the third repeat. These are signals for review, not proof of misconduct. A web page cannot reliably detect operating-system screenshots or mobile screenshot gestures, so the application does not claim to detect them.

## Unattended timeout finalization

The browser asks the backend to submit as soon as its server-issued deadline reaches zero. For a local XAMPP installation, add a Windows Task Scheduler task every minute for truly unattended expiry processing (for example, when a browser is closed): program `C:\xampp\php\php.exe`, argument `C:\xampp\htdocs\BEREVION\expire-sessions.php`. The helper is CLI-only and cannot be opened from the web.

## Data and security

This is a PHP/MySQL application. Keep `.env`, historical `cbt-data.json`, backups, and private uploads out of the web root where production deployment allows. Exam access passwords and admin sessions are separate; administrators should sign out on shared computers. Repeated failed sign-ins are temporarily throttled per client to reduce password guessing.
