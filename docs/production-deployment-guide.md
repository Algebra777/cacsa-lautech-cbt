# Berevion production deployment guide

This guide is for a production host. It intentionally does **not** change the local XAMPP environment. Use a staging environment to rehearse every command and restore procedure before applying it to a live institution.

## 1. Target architecture

Run the web application, MySQL, and the PDF-import worker as separate processes. A small single-server deployment can place them on one hardened Linux virtual machine; larger deployments should move MySQL and object storage to managed services while keeping the same boundaries.

```text
Internet -> HTTPS load balancer / Nginx -> PHP-FPM application workers -> MySQL
                                              |
                                              +-> private object storage (logos, PDFs, backups)

systemd timer -> CLI PDF worker -------------> MySQL + provider APIs + private PDF storage
```

The web process must never run the worker inline. Upload requests only create a queued `pdf_import_jobs` row; the worker claims one job transactionally, performs extraction and AI processing, and records a review-ready, failed, or retryable state.

## 2. Baseline Linux stack

Use a currently supported LTS Linux distribution, Nginx, PHP 8.2+ with PHP-FPM, and MySQL 8.0+. Install PHP extensions required by this application: `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo`, `zip`, and the PDF parser's dependencies. Install Composer dependencies during the release build, never as a web request.

Create a dedicated non-login account such as `cbtapp`. It owns the release directory and can read the runtime environment file, but cannot read other application accounts. Store the real environment file at `/etc/cbt/cbt.env`, outside the release and outside the web root, with restrictive ownership and permissions; do not put it in Git. The current PHP CLI loader reads `current/.env`, so make that path a protected symlink to `/etc/cbt/cbt.env` as part of release activation and keep the Nginx deny rule for `.env` in place. This preserves current application behaviour without storing the secret material in the deployment artifact.

Recommended initial PHP-FPM settings must be sized during staging capacity testing, not copied blindly:

```ini
; pool: /etc/php/8.2/fpm/pool.d/cbt.conf
pm = dynamic
pm.max_children = <sized-from-staging>
pm.start_servers = <sized-from-staging>
pm.min_spare_servers = <sized-from-staging>
pm.max_spare_servers = <sized-from-staging>
pm.max_requests = 500
request_terminate_timeout = 120s
php_admin_value[memory_limit] = 256M
```

Enable OPcache for all PHP-FPM workers:

```ini
opcache.enable=1
opcache.enable_cli=1
opcache.validate_timestamps=0
opcache.memory_consumption=192
opcache.max_accelerated_files=20000
opcache.interned_strings_buffer=16
```

Deploy a new immutable release directory, run database migrations explicitly, switch the Nginx/PHP-FPM release symlink, then reload PHP-FPM. Restarting or reloading a service is never a substitute for a migration check or backup verification.

## 3. Nginx and HTTPS

Terminate TLS at Nginx (or an upstream load balancer) and redirect all HTTP requests to HTTPS. Use an automatically renewed certificate, modern TLS defaults, HSTS only after confirming every required hostname works over HTTPS, and request-size limits that accommodate the 10 MB PDF ceiling without allowing arbitrary uploads.

Example location structure (replace placeholders; do not copy credentials into this file):

```nginx
server {
    listen 443 ssl http2;
    server_name cbt.example.edu;
    root /srv/cbt/current;
    index index.html;
    client_max_body_size 12m;

    # TLS certificate, protocol and security-header configuration belongs here.

    location ~ /(?:\.env|database/|uploads/pdf-imports/|scripts/|vendor/) {
        deny all;
        return 404;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/php8.2-fpm-cbt.sock;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

Review application routing for `/i/{institution-slug}/` before every release. The platform root is Berevion; retain a legacy redirect only while its published migration window requires it. Set secure, `HttpOnly`, `SameSite` cookies and verify host/path scope for both tenant student pages and tenant admin pages after HTTPS is enabled.

## 4. MySQL production configuration and operations

Use a dedicated MySQL user with only the application database privileges it needs. Enable TLS between PHP and MySQL whenever they are on different hosts. Keep `utf8mb4` as the database, table, and connection character set. Do not relax the tenant filter discipline: every tenant query continues to bind `institution_id`.

Set MySQL capacity using staging evidence:

- Set `max_connections` above the measured PHP-FPM connection peak plus worker, migration, backup, and monitoring headroom; do not set it to an arbitrary very high value.
- Size InnoDB buffer pool to the working set while reserving memory for MySQL, PHP-FPM, Nginx, the operating system, and burst traffic.
- Enable the slow-query log with a conservative threshold (start around 500 ms), capture lock waits, and review indexed query plans after each release.
- Maintain automated logical backups and periodic physical/database-provider snapshots. Test both independently.

The application already uses targeted transactional writes, indexed endpoint queries, and tenant-scoped backup/restore paths. Production operations must preserve those guarantees: never restore a whole database to resolve one tenant issue, and never run ad-hoc SQL without an institution scope and an approved change record.

## 5. PDF import worker

The worker is a one-shot CLI program. Run it with a systemd timer, not from Nginx or PHP-FPM. The MySQL advisory lock already prevents two worker invocations from processing the same queued job concurrently.

`/etc/systemd/system/cbt-pdf-import.service`:

```ini
[Unit]
Description=CBT PDF import worker
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=oneshot
User=cbtapp
Group=cbtapp
WorkingDirectory=/srv/cbt/current
EnvironmentFile=/etc/cbt/cbt.env
ExecStart=/usr/bin/php /srv/cbt/current/scripts/work_pdf_import_jobs.php
StandardOutput=journal
StandardError=journal
```

`/etc/systemd/system/cbt-pdf-import.timer`:

```ini
[Unit]
Description=Run the CBT PDF import worker every minute

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
RandomizedDelaySec=5

[Install]
WantedBy=timers.target
```

After deployment: `systemctl daemon-reload`, `systemctl enable --now cbt-pdf-import.timer`, then confirm `systemctl list-timers` and inspect a worker run with `journalctl -u cbt-pdf-import.service`.

Keep provider keys only in `/etc/cbt/cbt.env` (and expose that same protected file to the current release through the `.env` symlink), readable by `cbtapp`. Alert on queued-job age, retries, `failed` jobs, and provider rejection/rate-limit errors. Do not bypass the queue for an urgent import: its draft-only review, provider limits, cooldowns, duplicate checks, and audit trail are mandatory controls.

## 6. Private object storage and backup lifecycle

Move logos, private PDF sources, routine backups, archive files, and restore safety backups to a private S3-compatible/object-storage bucket when institutions and data volume grow. Keep the existing `institution_assets` manifest as the authority for automated deletion; never delete by bucket prefix/directory scan alone.

- Use separate prefixes/buckets for tenant assets, private PDF sources, routine backups, audit archives, and safety backups.
- Block anonymous/public access and bucket listing. Serve user-visible files through an authorization-checked application endpoint or short-lived signed URL.
- Encrypt data at rest using provider-managed encryption with a customer-managed KMS key where available. Encrypt logical database backup archives before upload, retain encryption-key recovery procedures separately, and rotate access credentials.
- Keep current retention behaviour: temporary source PDFs are eligible for cleanup after their short post-completion/cancellation window; review-ready jobs expire and cancel safely; orphan assets require a manifest state plus a grace period; safety backups are excluded from automatic cleanup.
- Apply routine-backup retention tiers (for example daily, weekly, monthly) and document legal/institutional retention requirements before changing the defaults. A retention policy is not a substitute for restore tests.

Perform a documented restore drill at least quarterly: restore an encrypted tenant-scoped backup into an isolated staging tenant, validate row counts, snapshot hashes, GPA/CGPA calculations, branding, and login boundaries, then securely dispose of the drill environment. Record the recovery time and gaps.

## 7. Staging, migrations, maintenance, and rollback

Staging must be a separate host/account/database/bucket with the same PHP, MySQL, Nginx, worker, and HTTPS topology as production. Use anonymised production-like data or a generated load fixture; never copy live student passwords, active sessions, or real recovery codes into staging.

For every release:

1. Build and scan the release artifact; install locked Composer dependencies.
2. Run syntax, unit/integration, tenant-isolation, queue, and storage-lifecycle checks in staging.
3. Take and verify a fresh production backup before any schema/data migration.
4. Run additive, idempotent migrations first. Use the maintenance flag only for a planned write-sensitive cutover, show the friendly maintenance message, and record start/end times.
5. Run smoke tests for tenant student flow, tenant admin flow, autosave, submission, results, audit events, backup creation, and a queue job.
6. If validation fails, roll back application code immediately. Restore data only from the pre-change safety backup when a reversible migration cannot safely be rolled forward; record the decision and perform a post-restore integrity check.
7. Remove maintenance mode only after the smoke tests pass and the worker is healthy.

Keep schema migrations forward-compatible and reversible where practical. A rollback plan must identify the exact code release, migration identifiers, backup filename/checksum, owner, communication message, and approval authority.

## 8. Monitoring, alerting, and operational dashboards

Collect logs and metrics centrally. At a minimum, alert on the following rather than discovering them during an exam window:

| Area | Measure and alert condition |
| --- | --- |
| HTTP | Availability, 4xx/5xx rate, p50/p95/p99 latency, request rate, TLS certificate expiry |
| PHP-FPM | Active/idle workers, listen queue, `max_children` reached, worker restarts, memory use, fatal errors |
| MySQL | Connections/connection failures, slow-query count, query latency, InnoDB lock waits/deadlocks, buffer-pool pressure, disk space, replication/snapshot health where used |
| Queue | Oldest queued-job age, running-job age, failed/cancelled/retry counts by provider, provider 429/5xx responses, review expiry count |
| Storage | Object/local disk use and growth, failed asset cleanup, orphan-manifest candidates, backup/archive volume |
| Backups | Scheduled backup failure, checksum mismatch, missed backup window, restore-drill age and duration |
| Security | Repeated blocked login attempts, suspension/deletion events, tenant-boundary errors, unexpected platform-admin access attempts |

Maintain an on-call runbook for active examination periods: who can place the portal in maintenance, how to disable a bad component, how to inspect queue failures without exposing PDFs or API keys, and how to communicate status to institutions.

## 9. Staging capacity test and sizing gate

The local XAMPP results are a useful application comparison, not production capacity certification. Before onboarding an institution, recreate a production-like fixture in staging and run a controlled test at 300, 500, and 1,000 concurrent student sessions. Include the realistic mixture: assessment login/start, question selection, autosaves, submission bursts, dashboard/results/audit reads, backups outside the peak window, and queue-worker activity.

For each load level, record p50/p95/p99 latency, error rate, PHP-FPM queue depth and saturation, MySQL connection/lock/slow-query metrics, CPU, memory, disk I/O, and autosave success rate. Size PHP-FPM `max_children`, MySQL `max_connections` and memory, Nginx limits, and host/database tiers from those measurements. Repeat after meaningful schema, provider, or infrastructure changes.

Do not certify 1,000 concurrent students unless staging demonstrates the agreed latency/error targets with headroom during the start-of-exam and autosave bursts. If a single host cannot meet the target, move PHP-FPM behind a load balancer and use a managed/appropriately sized MySQL service before raising connection limits blindly.

## 10. Scalability initiative summary (Stages 1–4)

| Stage | Delivered outcome |
| --- | --- |
| 1 | Replaced whole-tenant delete/reinsert mutations and the global data lock with targeted transactional MySQL writes for high-frequency paths. The 30-simultaneous-autosave worst case improved from about 10.5 seconds to 0.44 seconds. |
| 2 | Replaced tenant-wide reads with endpoint-specific queries and added indexes for the query paths that use them. Autosave reads now load only the required attempt/answer data rather than a complete tenant dataset. |
| 3 | Moved dashboard, Results, and Audit Log aggregation/filtering/pagination into indexed SQL; limited recalculation to relevant changes; made rate limits route/identity-aware. With the 300-student, 600-submission, 3,000-audit-event fixture, these views tracked the health-check baseline rather than historical data volume. A controlled autosave retest remained about 0.294–0.345 seconds. |
| 4A | Added manifest-gated logo/backup lifecycle retention and dry-run-by-default cleanup, preserving safety backups. |
| 4B | Added durable MySQL PDF-import jobs, private temporary source storage, CLI processing, review-only Draft imports, retries, expiry, cancellation, and tenant-scoped verification. |
| 4C | This production deployment, operations, monitoring, backup/restore, and staging-capacity guide. |

The application-level bottlenecks identified in the original audit are resolved or controlled. The remaining unvalidated boundary is infrastructure capacity: the local 1,000-concurrent plateau reflected local Apache/PHP worker limits, not the optimized application queries. A real Linux staging host must complete the capacity-sizing gate above before 1,000 concurrent students can be promised in production.
