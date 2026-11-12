# Performance and concurrency measurement, Epic-01 (task 44, AC-88)

Measurement only (design G6): results are recorded as measured; nothing here is a guarantee of the design. Run on 2026-11-12.

## Environment

- One developer machine (WSL2 Linux, 16 logical CPUs), Docker Compose stack: `app` (FrankenPHP classic mode, PHP 8.4.27, opcache on, **no Twig template cache**, DI container built on every request, `APP_ENV` not production), `db` (PostgreSQL 18, default configuration, no tuning), `mailpit`.
- The load generator ran on the same machine as the app and the database, so they competed for the same CPUs. Numbers from a separate production-sized host will differ.
- Data: 10,001 users (`php bin/console dev:seed-users 10000` plus one Super Admin). Production Argon2id cost: PHP defaults m=65536 KiB, t=4, p=1; measured on this machine: hash 221 ms, verify 204 ms.

## What was run

| Target | Method | Command |
|---|---|---|
| Dashboards < 2 s | In-process requests through the full middleware pipeline, 10,000 users plus a Trainer with a 300-player roster | `docker compose run --rm app vendor/bin/phpunit --testsuite Performance --filter DashboardTimingTest` |
| Users list of 10,000 < 3 s, paginated | Same method: first page, page 200, search, role+status filter | `... --filter UsersListTimingTest` |
| Profile save < 1 s | Trainer, Coach, Player saves on a 10,000-user database | `... --filter ProfileSaveTimingTest` |
| ShareLink registration < 2 s | Five registrations with the production Argon2id cost on a 10,000-user database | `... --filter ShareLinkRegistrationTimingTest` |
| Index review | `EXPLAIN ANALYZE` of the list, count, search, role filter, sign-in throttle (email and IP) and session lookup queries | `... --filter QueryPlanTest` (plans in `var/perf/plans.txt`) |
| 1,000 concurrent users | Node load generator over HTTPS against the running app, 1,000 virtual users | `LOAD_EMAIL=... LOAD_PASSWORD=... scripts/load/loadtest.sh` |

`docker compose run --rm app vendor/bin/phpunit --group perf` runs all of the timing and plan tests (each writes its numbers to `var/perf/results.jsonl`, ignored by Git).

## Results: per-request timings (in process, no network or TLS)

| Measure | Target | Measured | Result |
|---|---|---|---|
| Trainer dashboard / players / availability | < 2 s | 19 ms / 15 ms / 31 ms | within target |
| Coach dashboard / schedule | < 2 s | 7 ms / 9 ms | within target |
| Parent family dashboard / children / events | < 2 s | 5 ms / 13 ms / 8 ms | within target |
| Admin dashboard / Users list | < 2 s | 1 ms / 14 ms | within target |
| Users list, 10,001 users: page 1, page 200, search "seed user 77", coach+active filter page 3 | < 3 s | 12 ms, 10 ms, 18-26 ms, 5 ms | within target |
| Profile save, Trainer / Coach / Player | < 1 s | 5 ms / 3 ms / 12 ms | within target (profile photo upload save: existing test in `EditProfileTest`, also under 1 s) |
| ShareLink registration with production hashing | < 2 s | 169-221 ms (five runs) | within target |

Index review (10,001 users, 20,000 failed sign-in attempts, 10,000 sessions): session lookup uses `sessions_pkey` (0.02 ms); sign-in throttle uses `login_attempts_email` / `login_attempts_ip` (0.03-0.09 ms); the role+status filter uses `users_role_status_created` (0.1 ms). The unfiltered list and the name/email search use a sequential scan plus sort at this size (5.6 ms and 5.8 ms); the trigram indexes exist and the planner is expected to use them as the table grows. No index was added.

## Results: 1,000 concurrent users (load run, 30 s ramp then 60 s hold, 1.5 s think time)

Each virtual user opens `/login`, signs in (Argon2id verify) as the dev Super Admin, then alternates `GET /admin/users?page=N` (random page of 10,001 users) and `GET /admin` with think time. 23,572 requests in 96 s, 247 requests/s, **0 failed requests, 0 timeouts**.

| Step | Requests | p50 | p95 | p99 | max |
|---|---|---|---|---|---|
| GET /login | 1,000 | 2.4 s | 5.3 s | 5.5 s | 5.7 s |
| POST /login (Argon2id verify) | 1,000 | 3.6 s | 6.8 s | 7.4 s | 7.8 s |
| GET /admin/users?page=N | 10,786 | 1.2 s | 5.6 s | 6.1 s | 6.7 s |
| GET /admin | 10,786 | 1.2 s | 5.3 s | 6.0 s | 6.5 s |

Reading: the platform kept 1,000 concurrent signed-in users on this single dev container without errors, but under that load the page latency is not within the 2 s / 3 s targets at p95 (about 5 to 6 s), and the median of 1.2 s is already high for pages that take 1 to 30 ms in process. The queue is the PHP worker pool in one FrankenPHP container sharing 16 CPUs with the database and the load generator, not the database (all plans above are single-digit milliseconds). AC-88's "serves 1,000 concurrent users" is therefore **met for availability (no errors) and not met for the latency targets under that load in this environment**.

Likely causes and levers (not applied; this task is measurement only): Twig is compiled on every request (`TwigFactory::create` is called without a cache path in `config/container.php`), the PHP-DI container is built on every request, FrankenPHP is in classic mode (no worker mode), one app container, development settings. A production deployment would enable the Twig cache and the compiled container, size PHP threads to the host, and run more than one app instance behind a proxy. Re-run `scripts/load/loadtest.sh` after any such change; do not read the numbers above as a production figure.

## Not measured

- The load run drove authenticated reads of the Super Admin screens only. Dashboards of the other roles and registration under concurrent load were not exercised by the load generator (they were measured one request at a time above).
- No separate load host, no production-like database tuning, no sustained soak (hold time 60 s), no concurrency run with 1,000 simultaneous ShareLink registrations.
- Browser rendering time (client side) is not included; all times are server response times.

## Side effects of the run (dev data)

The dev database now holds 10,000 `seed-*@example.test` users and one Super Admin `a11y-admin@example.test` (created for the accessibility and load runs). The test database is separate and clean. While preparing this task it was found that the HTTP tests ran against the dev database `app` because `APP_ENV` was not `testing`; `phpunit.xml.dist` now sets `APP_ENV=testing` so they use `app_test`.
