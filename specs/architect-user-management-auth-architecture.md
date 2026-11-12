# Design: User Management & Authentication (Epic-01)

Spec (what/why, authoritative): `specs/requirements-analyst-user-management-auth-spec.md` (AC-1..AC-89, edge-case table, Open questions Q-01.01..Q-01.18, all defaults accepted 2026-11-12).
This file answers *how*, and records what was rejected. It does not restate requirements; AC ids point back to the spec.

## Approach

One server-rendered, framework-free PHP 8.4 application (Composer PSR-4, root namespace `App\`, single `src/`), run by FrankenPHP in classic (non-worker) mode behind its built-in Caddy, backed by PostgreSQL 18 through PDO. HTTP goes through one front controller (`public/index.php`) into a PSR-15 middleware pipeline and a PSR-15-aware router; controllers are thin and call use-case classes; use cases depend on repository/port interfaces implemented with PDO or adapters in each module's `Infrastructure/`.

The code is split into **modules (vertical slices) with layers inside each module**: `Identity`, `Organization`, `Onboarding`, `Family`, `Approval`, `Availability`, `Admin`, plus a `Shared` kernel. Dependencies point inward (Http -> Application -> Domain; Infrastructure implements interfaces declared by Domain/Application). Modules call each other only through the other module's Application-layer interfaces, never its repositories or tables.

The tenant is the **Trainer organization**. Every tenant-owned row carries `organization_id`; every tenant query receives an explicit `OrganizationId` from the resolved request context, and a miss is a 404. Authentication is a server-side session stored in PostgreSQL (so all sessions of a user can be revoked), CSRF uses a per-session synchronizer token, and every side effect that leaves the process (email, file deletion, payment hand-off) is written as intent inside the DB transaction and executed after commit by a worker. Integrations owned by other epics (events = Epic-02, payments/tokens = Epic-05, camp forms = Epic-08) are **ports with stub adapters** only.

Runtime is Docker-only: `docker compose` with `app` (FrankenPHP), `worker` (same image, CLI loop), `db` (PostgreSQL 18) and `mailpit` (mail catcher). Composer, migrations, tests, PHPStan and PHP-CS-Fixer all run inside the `app` image.

## Components

### C1. Directory and module layout

```
bin/console                      # hand-rolled CLI dispatcher (worker, scheduler, user:create-super-admin)
config/{container.php,routes.php,settings.php,middleware.php}
db/migrations/                   # Phinx migrations (raw SQL via $this->execute)
public/index.php                 # front controller; public/assets/{css,js,img}
templates/{layout,auth,admin,trainer,coach,family,profile,errors,email}/*.twig
src/
  Shared/       Domain (Clock, Uuid, Email, Phone, HexColor, Result types), Infrastructure (Pdo/Database+TransactionManager,
                Audit/AuditLogger, Outbox, Mail, Notification, Storage, Image, Security headers, Twig view), Http (Kernel, base middleware)
  Identity/     users, credentials, roles, sessions, login throttle, password reset, email verification, setup link, RBAC/policies
  Organization/ trainer organizations, coach memberships, coach invitations, branding, coach public profile
  Onboarding/   share links, link usage, registration (link, prefill), join-family flow, child-link block
  Family/       player profiles (Me + children), trainer associations, context selector, child permission matrix
  Approval/     approval requests (purchase / token / RSVP), token-spend setting, expiry, payment hand-off port
  Availability/ player weekly availability, coach weekly schedule, best times, coach assignment + override
  Admin/        Users tool, edit/deactivate/reactivate/delete(anonymize), impersonation + history
  Integration/  ports + stubs: Events (Epic-02), Payments (Epic-05), Camp prefill writer (Epic-08)
tests/{Unit,Integration,Http}/   docker/  docker-compose.yml
```

Each module: `Domain/` (entities, value objects, repository interfaces, domain exceptions), `Application/` (use cases, input DTOs, query services, ports), `Infrastructure/` (PDO repositories, adapters), `Http/` (controllers, request validators). Module boundary rule is checked by a PHPStan custom rule or review (see Decisions D17).

### C2. Entry point, router, middleware pipeline

`public/index.php` builds the PHP-DI container, creates the `ServerRequest` (nyholm/psr7-server), runs the pipeline, emits with laminas-httphandlerrunner. Router: league/route (FastRoute underneath) with route groups carrying their own middleware.

Global pipeline (order matters):

| # | Middleware | Responsibility | ACs / edges |
|---|---|---|---|
| 1 | `ErrorHandlerMiddleware` | Maps typed exceptions: `NotFound`/`TenantMismatch` -> 404, `Forbidden` -> 403 page, `ValidationFailed` -> re-render form 422, `Conflict` -> 409 notice; never leaks stack traces | AC-9, AC-10 |
| 2 | `SecurityHeadersMiddleware` | CSP with per-request nonce (`script-src 'self'`, `style-src 'self' 'nonce-…'`, `object-src 'none'`, `frame-ancestors 'none'`, `form-action 'self'`, `base-uri 'self'`), HSTS, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` (keeps tokens out of Referer) | AC-11, XSS |
| 3 | `SessionMiddleware` | Loads `sessions` row by SHA-256 of cookie `__Host-sid` (Secure, HttpOnly, SameSite=Lax, Path=/); idle 7 days, absolute 30 days; touches `last_seen_at` at most once per minute; regenerates ID on login, logout, privilege change, impersonation start/end | AC-8, S1 |
| 4 | `CsrfMiddleware` | For non-safe methods: require `_csrf` field or `X-CSRF-Token` header equal (hash_equals) to the session token, plus Origin/Referer same-origin check; fails -> 403 | AC-11 |
| 5 | `AuthenticationMiddleware` | Resolves `CurrentUser`; ends session if the user is inactive/deleted (edge "deactivated user has valid session"); enforces `must_set_password` redirect | AC-8, AC-15, AC-73 |
| 6 | `ImpersonationMiddleware` | If session carries an impersonation: end it when `started_at + 1h <= now` or when the session-only marker cookie `__Host-imp` is missing (browser closed); exposes banner data; audit-logs every non-safe request with `impersonator_user_id` | AC-52..AC-55 |
| 7 | `ContextMiddleware` | Resolves the active `RequestContext` (organization + acting player profile) from the session; validates membership on every request; falls back to the first valid context | AC-10, AC-24, AC-25, AC-36, AC-37 |
| 8 | `BrandingMiddleware` | Loads the active organization's logo/color into the view (cached per request) | AC-81..AC-83 |

Route-group middleware: `RequireGuest`, `RequireAuth` (unauthenticated GET -> `/login?return_to=<relative path>`; unauthenticated POST -> login redirect, body discarded), `RequireRole(role…)`, `RequireVerifiedEmail`, `DenyChildLogin` (child capability matrix, C9), `LoginThrottle` (on `POST /login` only).

`return_to` accepts only a same-origin relative path starting with a single `/` (open-redirect guard).

### C3. Authorization model

- **Coarse:** `RequireRole` per route group (`/admin/*` super_admin, `/trainer/*` trainer, `/coach/*` coach, `/family/*` player).
- **Fine:** one Policy class per aggregate (`UserPolicy`, `OrganizationPolicy`, `PlayerProfilePolicy`, `ApprovalRequestPolicy`, `ShareLinkPolicy`, `CoachInvitationPolicy`) invoked by the use case, not the controller, so CLI/worker paths are covered too. Policies receive `CurrentUser` + `RequestContext` + target.
- **Tenant scoping:** repositories for tenant data expose only methods that take `OrganizationId`; SQL always has `AND organization_id = :org`. There is no "find by id" for tenant data without an org. A record outside the org is reported as not found (404), never 403, to avoid confirming existence (edge "Trainer opens another Trainer's user by address").
- **Child matrix:** `ChildCapabilities` lists allowed actions (AC-46); `DenyChildLogin` guards every route in the forbidden list (AC-47) and the use cases re-check (`Forbidden` on direct address).
- **Impersonation:** the effective `CurrentUser` is the impersonated user, so navigation, permissions and data match exactly; the impersonator is carried separately and only used for audit and the banner.

### C4. Identity (sessions, login, passwords, tokens)

- **Passwords:** `password_hash(PASSWORD_ARGON2ID)`, `password_needs_rehash` on successful login. Policy: 10-128 chars, checked against a small bundled common-password list; no composition rules (assumption, NIST 800-63B style). Never logged, never emailed (AC-4, Q-01.08).
- **Email:** `Email` value object trims and lowercases; `users.email` has `CHECK (email = lower(email))` and `UNIQUE` -> case-insensitive uniqueness backed by the DB (AC-6, edge "any letter case"). The unique violation (SQLSTATE 23505) is mapped to "email already registered".
- **Login:** `LoginUser` use case: throttle check first (refuses even a correct password while locked), then lookup + `password_verify` (always runs a dummy verify for unknown emails to equalize timing), then status checks: `inactive` -> "Account deactivated. Contact support.", `deleted` -> generic failure, unverified self-registered -> "please verify your email" + resend link (Q-01.05). Generic "Email or password is incorrect" otherwise (AC-2). Success: regenerate session, redirect to role dashboard (`/admin`, `/trainer`, `/coach`, `/family`) (AC-1).
- **Throttle:** `login_attempts(email, ip, attempted_at, succeeded)`; locked when >= 5 failures for the same email OR the same IP in the last 15 minutes; lock lasts until the 5th failure is 15 minutes old (Q-01.10, AC-3). Keyed on both so one attacker cannot lock across many emails silently and one email cannot be sprayed from many IPs without limit. IP taken from the socket, or from `X-Forwarded-For` only when the proxy is in a configured trusted list.
- **One-time tokens** (`auth_tokens`): purposes `password_reset` (1 h), `email_verification` (24 h), `trainer_setup` (7 days, assumption). 32 random bytes, base64url in the link, only SHA-256 stored; consumed with `UPDATE … SET used_at = now() WHERE token_hash = :h AND used_at IS NULL AND revoked_at IS NULL AND expires_at > now() RETURNING user_id` (atomic single use). Issuing a new token revokes earlier live tokens of the same user and purpose (edges "earlier link invalidated"). Unknown email on reset -> same neutral message, no email (edge). Completing a reset deletes all other sessions of that user (edge) and records a password-changed audit event.
- **Session timeout during form fill:** handled by `RequireAuth` (C2): login, then return to the page via GET; POST payload is not replayed (edge).

### C5. Organization (tenant), coaches, branding

- `organizations` 1:1 with the Trainer user (`owner_user_id UNIQUE`), holds business name, details, `logo_path`, `brand_color`, `status` (`active`/`inactive`). Created only by `CreateTrainer` (Admin, AC-12/13); there is no trainer self-registration route.
- `CreateTrainer` (one transaction): user (role trainer, status active, `must_set_password = true`, email verified on setup completion), organization, `trainer_setup` token, outbox email with setup link, audit `trainer.created` (AC-13..AC-17). AC-15's temporary password does not apply (setup link chosen, Q-01.08); `must_set_password` still forces password choice before any other page.
- **Coach invitations:** `coach_invitations(id, organization_id, email, name, message, token_hash, expires_at = created + 7 d, accepted_at, accepted_by, revoked_at, created_by)`. Status is derived (Accepted if `accepted_at`, Expired if `expires_at <= now()`, else Pending), so no expiry job is needed (AC-57, AC-58, AC-60). Resend = revoke old + create new invitation + email. Several pending invites to the same email from different Trainers may coexist (edge).
- **Accepting:** `AcceptCoachInvitation` locks the invitation row (`FOR UPDATE`), checks unused/unexpired/unrevoked, registers or logs the coach in (existing coach must be logged in as the invited email), inserts `coach_memberships(status='active')`. A partial unique index `UNIQUE (coach_user_id) WHERE status = 'active'` makes "active under at most one Trainer" a DB invariant; the violation maps to the AC-59 message (edges "already active under another Trainer", "first accepted wins").
- **Branding:** `UpdateBranding` validates the logo (C11) and `HexColor` (`^#[0-9A-Fa-f]{6}$`), stores the new file first, updates the row, deletes the old file after commit (previous logo/color remain on any failure, edges). Reset = null logo/color -> default logo `Task/designs/default_logo.svg` copied to `public/assets/img/` and default accent. `BrandPalette` derives `--brand-primary`, `-soft`, `-deep`, `-rgb` and an accessible on-brand text color (black or white by WCAG contrast ratio) and emits them as CSS custom properties in a nonce'd `<style>` in the layout, only for requests whose active context is that organization (AC-83). Live preview: vanilla JS updates the CSS variable and an object-URL image; without JS a "Preview" submit renders the page with staged, unsaved values (AC-81, AC-82).

### C6. Onboarding (ShareLinks, registration, prefill)

- `share_links(id, organization_id, kind = 'player', code, active, created_at, deactivated_at, use_count, last_used_at)`: static player link, unlimited, no expiry; `code` = 22-char base62 (128-bit) random, stored plain because the Trainer must be able to re-display it; one active player link per organization (partial unique), Trainer may regenerate (old link deactivated) (AC-18, AC-28). Coach links are the invitations in C5 (single-use, 7 days). Both are "ShareLinks" in UI terms.
- `share_link_uses(id, share_link_id, user_id, player_profile_id, outcome, used_at)` records every association/registration produced, plus `use_count`/`last_used_at` increments (AC-23).
- `ResolveShareLink`: unknown code, inactive link, or organization not `active` -> one generic "This link is not valid" page, nothing written (AC-26, edges).
- Flow by visitor state (`GET /join/{code}`):
  - not logged in -> registration/login choice; `RegisterViaShareLink` (one transaction): user (role player, unverified), player profile for "Me" (if 18+) and/or child profile (if registering a child: birth date -> age 0-17), associations to the link's organization, link use, outbox: confirmation + verification email (AC-19, AC-20). Duplicate email -> refused, nothing created.
  - logged-in adult player without children -> instant association, notice if already associated, redirect to that Trainer's events placeholder page (AC-21, edge "already belong").
  - logged-in parent with children -> "Who will train with [Trainer]?" checklist (Me + each child; Me shown only if the parent has a Me profile, all unticked by default, children previously removed by the parent are never pre-ticked); zero ticks -> validation error "choose at least one person or cancel" (AC-22, edges).
  - logged-in child login -> block page "Ask your parent to register you with this trainer", no association, outbox email to the parent with subject "[Child Name] wants to join [Trainer Name]'s program" and a "Review Registration" button linking to `/family/join/{code}?child={profileId}` (parent must log in; ownership checked) (AC-49).
  - trainer/coach/super admin -> "this link is for players" page.
- Re-association after a parent removal creates a new association row (history of the old one kept) only through the parent's choice (edge).
- **Camp prefill (AC-85):** Epic-08 calls the `RegistrationPrefillWriter` port, which stores `registration_prefills(id, organization_id, token_hash, data jsonb, expires_at = +14 d, consumed_at)` and returns a link `/register/prefill/{token}`. No PII in the URL. Valid token -> registration form pre-filled and association to that organization; tampered/unknown/expired/consumed -> ordinary blank registration (edge). Email already registered -> "please log in"; the prefill token is kept in the session and attached after login (edge). The alternative path is the `SendShareLinkByEmail(organizationId, email)` use case exposed to Epic-08.

### C7. Family (player profiles, associations, context selector)

- `player_profiles(id, owner_user_id, kind 'self'|'child', login_user_id UNIQUE NULL, first_name, last_name, birth_date, gender, school, jersey_number, photo_path, skill_level, token_spend_without_approval bool default false, emergency_contact jsonb, created_at, deleted_at)`; partial unique `(owner_user_id) WHERE kind = 'self'`. Child: `birth_date` must give age 0-17 (Q-01.03) with message naming the range (AC-27, AC-28). Age is derived from birth date (Q-01.02). One guardian per child (Q-01.18).
- `AddChild`: similarity check (same owner, case-folded name equal or Levenshtein <= 2 and same birth year) returns a warning; re-submit with `confirm_similar=1` proceeds (AC-30). Then the AC-29 prompt (one Trainer: yes/no; several: checklist; none: skip).
- `player_trainer_associations(id, player_profile_id, organization_id, source_share_link_id, associated_at, ended_at, ended_by, end_reason)`; partial unique `(player_profile_id, organization_id) WHERE ended_at IS NULL`. "Association dates" for AC-33 come from `associated_at`.
- `AddChildToTrainer` (ShareLink code or one of the parent's own organizations) (AC-34). `RemoveChildFromTrainer`: confirmation page listing that upcoming RSVPs will be cancelled; on confirm sets `ended_at` (soft delete), calls the Epic-02 port `EventRegistrations::cancelUpcomingFor(profile, org)` after commit (via outbox job), and roster queries filter `ended_at IS NULL` (AC-35, edge).
- **Context selector:** `ListContexts(user)` builds groups "Your Training" (self profile x orgs) and "Your Children's Training" (child x orgs) for parents; only own orgs for child logins (no "Me", no parent data); none for trainers/coaches (org fixed). Switching posts `(profile_id, organization_id)`, validated, stored in the session row so it persists for the session (AC-24, AC-25, AC-31, AC-36, AC-37). Per-child calendar/RSVP/attendance (AC-32) are keyed by `player_profile_id + organization_id` in Epic-02; this epic provides the key and the context.
- **Child login creation (spec gap, assumption):** parent optionally enables a login on a child profile by setting an email + password; creates `users(role player, account_kind child, guardian_user_id)` linked through `player_profiles.login_user_id`, marked verified by the parent's action.

### C8. Approval workflow (US-01.05, Q-01.09)

- `approval_requests(id, organization_id, child_profile_id, child_user_id, parent_user_id, kind 'purchase'|'token_spend'|'rsvp'|'rsvp_cancel', event_ref uuid, amount_minor, currency, payment_type 'usd'|'tokens'|'none', status, rule_snapshot jsonb, child_note, parent_note, created_at, expires_at = created + 48 h, decided_at, decided_by, version)`.
- Status machine: `pending` -> `info_requested` -> (`approved` -> `completed` | `payment_failed`) | `denied` | `expired`; `auto_denied` when the guardian is deactivated/deleted. "Info requested" does not extend the 48 h clock (assumption).
- `RequestPurchase` / `RequestRsvp` (child login): USD always creates a request; tokens create a request when the snapshot setting is OFF, otherwise call the Epic-05 `TokenSpend` port directly and enqueue an informational parent notice (AC-38, AC-42, AC-43). The setting is snapshotted into `rule_snapshot` so later changes do not affect pending requests (edge).
- `DecideRequest` (parent): `UPDATE approval_requests SET status = :new, decided_at = now(), decided_by = :parent, parent_note = :note WHERE id = :id AND parent_user_id = :parent AND status IN ('pending','info_requested') AND expires_at > now() RETURNING …`. Zero rows -> reload and refuse with current status or "request expired" (AC-45, edges "twice", "after expiry"). Only the child's own parent matches the predicate.
- Approval hand-off (AC-41): commit `approved`, then an outbox job calls `PurchaseHandoff::submit()` (Epic-05 port) outside any transaction; success -> `completed` and `EventRegistrations::register()` (Epic-02 port); failure -> `payment_failed`, both notified, child not registered (edge). Until Epic-05 exists the binding is `PendingPaymentHandoff`, which leaves requests `approved` and logs "payments not available".
- Notifications: email via outbox + in-app `notifications(user_id, type, payload jsonb, created_at, read_at)` (AC-39, AC-40, AC-44).
- Expiry: scheduler task `approvals:expire` every minute: `UPDATE … SET status='expired' WHERE status IN ('pending','info_requested') AND expires_at <= now() RETURNING id` and enqueues notices to parent and child (AC-44). Correctness never depends on the job running on time because `DecideRequest` checks `expires_at` itself.

### C9. Child capability matrix (AC-46..AC-48)

Allowed: browse events (view), assigned content, own progress, submit feedback request (entry point only), basic profile (photo, preferences), view tokens, switch own contexts. Denied (route guard + use-case check, 403 "not allowed for child accounts"): add Trainers / use links (C6 block), payment methods, buy tokens, complete purchases (C8), delete account, change associations, view parent data. RSVP/cancel go through C8 with kind `rsvp`/`rsvp_cancel` (Q-01.09).

### C10. Availability and coach assignment (US-01.09/10)

- `availability_slots(id, subject_type 'player_profile'|'coach', subject_id, weekday 0-6, starts_at time, ends_at time)` with `CHECK (ends_at > starts_at)` and an exclusion constraint `EXCLUDE USING gist (subject_type WITH =, subject_id WITH =, weekday WITH =, tsrange-equivalent int4range(minutes) WITH &&)` (needs `btree_gist`) -> overlaps refused by DB and pre-checked by the validator with a message naming the problem (AC-64, edge). `availability_unavailable_days(subject…, weekday)` for explicit "Not Available" (AC-61). No rows at all = "no preference"; filters never treat it as unavailable (edge).
- Times are wall-clock in the organization's time zone (`organizations.timezone`, default from config). Player availability is per profile and visible to each organization the profile is associated with (see Gaps G2).
- `SaveAvailability` replaces a subject's week in one transaction, flashes the AC-62 text.
- Trainer views: `PlayerAvailabilityQuery(org, weekday?, time?)` joins only active associations of that org; "Best Times" = top 3 weekday/time windows by slot coverage computed in SQL/PHP per card, batched for the page (no N+1) (AC-63). Advisory only; nothing in scheduling reads it as a blocker.
- `AssignCoach(event_ref, coach)` checks the coach's schedule; conflict -> warning page with the exact AC-65 sentence; continuing requires `reason` non-blank after trim (edge). Writes `coach_assignments(event_ref, organization_id, coach_user_id, status 'assigned'|'accepted'|'change_requested', override_id)` and `coach_availability_overrides(id, organization_id, event_ref, coach_user_id, reason, overridden_by, created_at)` + audit (AC-66, AC-87). Coach sees assignments on `/coach` and can accept or request change (AC-67); coach is notified (Q-01.06). Changing availability later never touches existing assignments (edge). `event_ref` has no FK until Epic-02 ships its events table.

### C11. File storage and image handling

- `FileStorage` port; `LocalFileStorage` writes under `/app/var/storage/{logos,photos,tmp}` (outside `public/`), random 128-bit file names, never user-supplied names. Served by `MediaController` (`GET /media/{kind}/{id}`) with authorization (logos: members of that org; photos: owner, guardian, and Trainers/Coaches of an organization the profile is associated with), correct `Content-Type`, `nosniff`, `Cache-Control: private`.
- Validation: upload error code, size <= 2 MB (also `upload_max_filesize=3M`, `post_max_size=8M`), `finfo` MIME in {image/png, image/jpeg} (+ image/svg+xml for logos only), `getimagesize` must succeed. Raster images are always re-encoded with GD (strips metadata and polyglot payloads); logos larger than 200x200 are scaled to fit 200x200 (AC-84); photos stored as 512 px max plus a 128 px thumbnail (AC-71, Q-01.12). SVG logos are sanitized with `enshrined/svg-sanitize` (remote refs off), rejected if the sanitizer reports issues, and served with `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'`; they are rendered only via `<img>`.
- Photo failure does not lose profile text: text fields and photo are processed separately; the text save commits, the photo error is shown and the old photo stays (edge).

### C12. Admin: Users tool, lifecycle, impersonation

- **Users tool** (AC-80): `/admin/users` with search (`ILIKE` on name/email, backed by `pg_trgm` GIN indexes), filters (role, status, organization), offset pagination 25/page, ordered by `created_at DESC, id`. `EditUser` uses optimistic locking: `users.version` in a hidden field, `UPDATE … WHERE id = :id AND version = :v`; zero rows -> "record changed, reload" (edge "two admins"). Email change happens only here (Q-01.11).
- **Deactivate** (AC-72, AC-73): status `inactive`, delete all sessions of that user, cascade per Q-01.14 (guardian -> child logins deactivated, pending approval requests `auto_denied` with notices) and Q-01.15 (trainer -> organization `inactive`, its links invalid, its context unavailable to members). **Reactivate** restores `active` (children/org reactivated explicitly with the parent/trainer) (AC-75). Inactive users stay in rosters/CRM marked "Inactive" because nothing is deleted (AC-74).
- **Delete/anonymize** (AC-76..AC-79): one transaction, with `SELECT … FOR UPDATE` on the user: refuse self-delete, refuse when the target is the last active Super Admin (count under lock), refuse a Trainer with active coaches until reassigned/deactivated (Q-01.15). Then: name -> "Deleted User", email -> `deleted_{id}@example.com`, phone/birth date/school/jersey/emergency contact/bio/credentials/password hash -> NULL, photos -> default avatar (file deletion via outbox after commit), availability rows deleted, sessions/tokens deleted, status `deleted`, audit `user.deleted {original_user_id, actor, reason}` only (Q-01.13). All foreign keys to users are `ON DELETE RESTRICT` and rows are never hard-deleted, so history and analytics totals stay intact (AC-77). `deleted` is terminal: reactivate refuses it (AC-78). The original email is free for a new registration (edge).
- **Impersonation** (AC-50..AC-56): `StartImpersonation` requires super_admin, target not super_admin, target not deleted (deactivated allowed for viewing, Q-01.16; while impersonating an inactive user the session is read-only - non-safe requests refused), and no impersonation already active (no nesting). Writes `impersonations(id, admin_user_id, target_user_id, started_at, ended_at, end_reason 'manual'|'timeout'|'browser_closed'|'logout'|'session_expired')`, regenerates the session ID, stores `impersonation_id` + `impersonator_user_id` on the session row, sets the session-only `__Host-imp` cookie. Banner partial in the layout: sticky, distinct color (contrast-checked), "Viewing as [User Name] | Exit Impersonation" as a POST form (AC-52, AC-53). Auto end at 1 h by middleware and by the scheduler sweep (ended_at = min(start + 1 h, last activity)) (AC-54, edge "notice"). Duration = `ended_at - started_at`, computed in the history report `/admin/impersonations` (AC-56).

### C13. Audit log (AC-17, AC-55, AC-66, AC-79, AC-87)

`audit_log(id bigint identity, occurred_at, actor_user_id, impersonator_user_id, action, subject_type, subject_id, organization_id, payload jsonb, ip)`. The app connects as role `app_rw`, which has only `INSERT, SELECT` on `audit_log`; a trigger additionally raises on `UPDATE`/`DELETE`/`TRUNCATE`. Migrations run as the owner role. Payloads carry IDs and non-personal business fields (e.g. business name, role), not emails/phones/names, so GDPR erasure never needs to edit the log (see D14). No UI edits the log for any role.

### C14. Mail, outbox, notifications, worker and scheduler

- `outbox(id, kind 'email'|'job', topic, payload jsonb, status, attempts, available_at, last_error, created_at)` written in the same transaction as the business change.
- `bin/console worker` (service `worker`): loop claiming rows with `FOR UPDATE SKIP LOCKED`, max 5 attempts with backoff, idempotent handlers (email handlers key on outbox id). Emails rendered from `templates/email/*.twig` (HTML + text), sent with symfony/mailer (`MAILER_DSN=smtp://mailpit:1025` in dev). Every email in AC-86 has one template: trainer setup link, registration confirmation, email verification, password reset, coach invitation, parent approval request, approval outcome (approved/denied/info/expired/failed), child-wants-to-join, token-spend notice, coach assignment notice, share link by email.
- Same process runs the scheduler tick every 60 s, each task guarded by `pg_try_advisory_lock`: `approvals:expire`, `impersonations:sweep`, `sessions:purge`, `tokens:purge`, `login-attempts:purge` (> 24 h). Coach invitation expiry needs no job (derived status).

### C15. Schema overview (PostgreSQL 18, Phinx migrations)

Extensions: `citext` not used (lowercase CHECK instead), `pg_trgm`, `btree_gist`. PKs `uuid DEFAULT uuidv7()` (native in PG 18) except `audit_log`/`login_attempts` (bigint identity). All timestamps `timestamptz`. Enums as `text` + `CHECK` (easy to extend in later epics).

| Table | Key constraints / indexes |
|---|---|
| users | UNIQUE email, CHECK email = lower(email), role CHECK in 4 roles, status CHECK active/inactive/deleted, account_kind standard/child, guardian_user_id FK (CHECK child => guardian not null), must_set_password, email_verified_at, version int, GIN trgm on name/email, index (role, status, created_at) |
| sessions | PK token_hash, user_id FK idx, csrf_token, last_seen_at idx, created_at, active_context jsonb, impersonation_id FK, impersonator_user_id |
| login_attempts | idx (email, attempted_at), idx (ip, attempted_at) |
| auth_tokens | UNIQUE token_hash, idx (user_id, purpose) |
| organizations | UNIQUE owner_user_id, status, brand_color CHECK hex, timezone |
| coach_memberships | partial UNIQUE (coach_user_id) WHERE status='active', idx organization_id |
| coach_invitations | UNIQUE token_hash, idx (organization_id, created_at) |
| coach_profiles | PK user_id, bio, credentials, certifications jsonb, is_public |
| share_links / share_link_uses | UNIQUE code, partial UNIQUE (organization_id) WHERE active; idx (share_link_id, used_at) |
| registration_prefills | UNIQUE token_hash |
| player_profiles | partial UNIQUE (owner_user_id) WHERE kind='self', UNIQUE login_user_id |
| player_trainer_associations | partial UNIQUE (player_profile_id, organization_id) WHERE ended_at IS NULL, idx (organization_id) WHERE ended_at IS NULL |
| availability_slots / availability_unavailable_days | CHECK ends_at > starts_at, EXCLUDE gist no overlap |
| coach_assignments / coach_availability_overrides | idx (organization_id, event_ref), CHECK length(trim(reason)) > 0 |
| approval_requests | idx (parent_user_id, status), partial idx (expires_at) WHERE status IN ('pending','info_requested') |
| notifications | idx (user_id, read_at) |
| impersonations | idx (admin_user_id, started_at), idx (target_user_id) |
| audit_log | append-only (grants + trigger), idx (action, occurred_at), idx (subject_type, subject_id) |
| outbox | partial idx (available_at) WHERE status='pending' |

Migrations: one reviewed Phinx migration per table group, raw SQL; run by `docker compose run --rm app vendor/bin/phinx migrate`; DB roles/grants created in the first migration with passwords from env.

### C16. Templates, accessibility, design tokens

- Twig with `autoescape: html` globally; `|raw` forbidden by convention and checked in review; CSRF field helper `{{ csrf_field() }}` in every form; CSP nonce exposed as `csp_nonce()`.
- `public/assets/css/tokens.css` maps `Task/designs/DESIGN_TOKENS.md` (typography scale, grayscale, spacing, radius, shadows) to CSS custom properties; `app.css` builds components (buttons, inputs, checkbox/radio/toggle, cards). Brand variables overridden per organization (C5).
- WCAG 2.1 AA (AC-89): semantic landmarks, skip link, one `h1` per page, labels bound to inputs, error summary at top linking to fields + `aria-describedby` / `aria-invalid`, visible `:focus-visible` ring, 44 px touch targets, mobile-first layout from 320 px, context selector and impersonation banner keyboard reachable, status messages in `role="status"`. Progressive enhancement only (vanilla JS for live brand preview, confirm dialogs fall back to confirmation pages).

### C17. Integration ports (stubs in this epic)

| Port (Application interface) | Owner | Epic-01 binding |
|---|---|---|
| `EventCatalog` (browse eligible events, event exists) | Epic-02 | `EmptyEventCatalog` (placeholder page) |
| `EventRegistrations` (register, cancelUpcomingFor) | Epic-02 | `NoopEventRegistrations` (logs) |
| `PurchaseHandoff`, `TokenSpend`, `TokenBalance` | Epic-05 | `PendingPaymentHandoff`, `UnavailableTokenSpend`, zero balance |
| `RegistrationPrefillWriter`, `SendShareLinkByEmail` | consumed by Epic-08 | real implementations (Onboarding) |

### C18. Docker dev/test setup

- `docker/app/Dockerfile` FROM `dunglas/frankenphp:1-php8.4`: `install-php-extensions pdo_pgsql gd intl opcache @composer` (Argon2 is built into the official PHP image), php.ini with `expose_php=0`, upload limits, `session.*` unused. Caddyfile serves `public/` on `https://localhost` (Caddy local CA) so `__Host-` Secure cookies work in dev.
- `docker-compose.yml`: `app` (ports 443/80, bind-mount repo), `worker` (same image, `php bin/console worker`), `db` (`postgres:18`, volume, healthcheck, init script creating `app` and `app_test` databases), `mailpit` (`axllent/mailpit`, pinned tag; UI on 8025, SMTP 1025; must be pulled once - not in the local image cache).
- Secrets only via `.env` (git-ignored, read by compose) and `.env.example` committed with placeholders.
- Commands: `docker compose run --rm app composer install`; `… app vendor/bin/phinx migrate [-e testing]`; `… app vendor/bin/phpunit`; `… app vendor/bin/phpstan analyse`; `… app vendor/bin/php-cs-fixer fix --dry-run --diff`; `… app php bin/console user:create-super-admin` (bootstraps the first Super Admin; no web route for it).
- Tests: Unit (domain, value objects, policies, palette), Integration (PDO repositories + use cases against `app_test`, each test wrapped in a rolled-back transaction; mailer swapped for an in-memory transport; `FrozenClock` for 1 h / 24 h / 48 h / 7 d expiries), Http (whole pipeline via PSR-7 requests: CSRF, role guards, tenant isolation matrix, child deny matrix). A dedicated **tenant-isolation suite** iterates every tenant route with a foreign organization's ids and expects 404.

## Stack

| Choice | Version | Over the alternative, because |
|---|---|---|
| PHP | 8.4 (FrankenPHP image `dunglas/frankenphp:1-php8.4`) | Only PHP runtime available locally; classic mode over worker mode (no cross-request state leakage risk while the codebase is young) |
| PostgreSQL | 18 (`postgres:18`) | Local image; native `uuidv7()`, partial unique indexes, exclusion constraints, `SKIP LOCKED`; over the local `postgis:17` (PostGIS not needed) |
| league/route | ^6.2 | PSR-15 route groups/middleware on FastRoute; over bare nikic/fast-route (would hand-roll dispatch + middleware) and Slim (framework-shaped) |
| nyholm/psr7 + nyholm/psr7-server | ^1.8 / ^1.1 | Small, strict PSR-7/17; over laminas-diactoros (heavier, same role) |
| laminas/laminas-httphandlerrunner | ^2.12 | Correct PSR-7 emitting (headers, streaming); hand-rolling it is error-prone |
| php-di/php-di | ^7.0 | PSR-11 with autowiring + compiled container; over hand wiring (~80 services) and league/container (weaker autowiring) |
| twig/twig | ^3.21 | Autoescaping by default (XSS), sandboxed templates, inheritance; over league/plates and raw PHP templates (escaping is opt-in per echo) |
| robmorgan/phinx | ^0.16 | Versioned migrations with status/rollback over PDO, raw SQL allowed; over doctrine/migrations (pulls DBAL) and a hand-rolled runner (locking, status, rollback to rebuild) |
| symfony/mailer | ^7.3 | SMTP/transport abstraction, MIME, in-memory test transport; over PHPMailer (no PSR-friendly transport swap) and `mail()` |
| giggsey/libphonenumber-for-php-lite | ^9.0 | Correct international phone validation + E.164 normalization (AC-70); regex would accept nonsense |
| enshrined/svg-sanitize | ^1.1 (amended 2026-11-12: Composer blocks 0.21 for 4 security advisories) | Maintained SVG XSS sanitizer for SVG logos (AC-81); hand-rolled SVG filtering is a known trap |
| ext-gd (no image library) | bundled | Only resize + re-encode needed; intervention/image adds a dependency for two calls |
| Hand-rolled: validators/DTOs, session store, CSRF, throttle, outbox worker, CLI dispatcher | - | Each is small, security-critical and needs DB integration (session revocation, throttle by email+IP) that generic packages do not give without adapters |
| Dev: phpunit/phpunit, phpstan/phpstan, friendsofphp/php-cs-fixer | ^12.0, ^2.1 (level 8), ^3.75 (`@PER-CS2.0`) | Policy-mandated test/static-analysis/style tooling; over Pest (no gain here) and Psalm (one analyser is enough) |
| Mail catcher | axllent/mailpit (pinned tag) | Web UI + API for checking emails; over MailHog (unmaintained) |

Exact patch versions are fixed by `composer.lock` at install time.

## Decisions

| # | Decision | Chosen | Rejected | Because |
|---|---|---|---|---|
| D1 | Code organization | Modules (vertical slices) with Domain/Application/Infrastructure/Http inside, single `src/` | Global layer folders (`src/Controller`, `src/Repository`…); microservices | Epic spans 7 bounded areas; slices keep each area's code together and boundaries reviewable; one deployable is enough for 1,000 users |
| D2 | Authentication | Server-side sessions in PostgreSQL, opaque cookie | PHP native file sessions; JWT | Must end all sessions of a user (reset, deactivate, delete) and carry impersonation state; JWT cannot be revoked without a store anyway |
| D3 | Session timeout | Idle 7 days (Q-01.07) + absolute 30 days (assumption) | Idle only | Bounds cookie theft lifetime; configurable |
| D4 | CSRF | Per-session synchronizer token + Origin check + SameSite=Lax | Double-submit cookie only; SameSite only | Defense in depth; synchronizer token is simplest with server sessions |
| D5 | Password hashing | Argon2id via `password_hash`, rehash on login | bcrypt | Memory-hard; native in PHP 8.4 image |
| D6 | Login throttle | DB table, 5 failures per email OR per IP in 15 min, 15-min lock | APCu/Redis counters | No extra service; works across app replicas; auditable |
| D7 | One-time tokens | Random 256-bit, SHA-256 at rest, atomic consume, new token revokes older | Signed stateless tokens | Single use and revocation require state |
| D8 | Tenant isolation | Explicit `OrganizationId` in every tenant repository method + 404 on mismatch + isolation test suite | PostgreSQL Row-Level Security; schema-per-tenant | Parents and Super Admins legitimately span organizations; RLS session vars add hidden coupling; schema-per-tenant breaks cross-org parent views. RLS stays an option for later hardening |
| D9 | Coach single-membership | Partial unique index on active membership | App-level check only | Race-proof (edge "first accepted wins") |
| D10 | Trainer onboarding | Setup link (7 days), no password by email (Q-01.08) | Temporary password | No secrets in email; AC-15 then not exercised |
| D11 | Email verification | Required before login for self-registered (Q-01.05); association created at registration | Optional verification | Accepted default |
| D12 | Side effects | Transactional outbox + worker | Send email inline in request | No email for a rolled-back change; no slow SMTP in request path (AC-88) |
| D13 | Approval decisions | Conditional `UPDATE … WHERE status IN (…) AND expires_at > now()` | Read-check-write | Double click / two devices / expiry race handled by the DB |
| D14 | Audit log content | IDs + non-personal fields only; append-only via grants + trigger | Full personal snapshot (AC-17 "details") | GDPR erasure (Q-01.13) must not require editing an immutable log; Trainer details recoverable via the user id while the user exists. Needs user confirmation that this satisfies AC-17 |
| D15 | Deletion | Anonymize in place, `ON DELETE RESTRICT` everywhere | Hard delete; archive copy | AC-77 totals unchanged; Q-01.13 forbids a PII backup |
| D16 | Child login | Separate `users` row (role player, kind child, guardian) linked to child profile | Child profile sub-login without users row | Reuses auth, sessions, throttle; still exactly one role (AC-9) |
| D17 | Module boundary enforcement | Convention + PHPStan custom rule / review | deptrac | Avoid an extra tool now; add deptrac if violations appear |
| D18 | Templates | Twig autoescape | Plates / raw PHP | XSS-safe by default |
| D19 | Migrations | Phinx, raw SQL | Doctrine Migrations; hand-rolled | Light, PDO-based, standard status/rollback |
| D20 | Images | GD re-encode + resize; SVG via sanitizer; private storage served by controller | Store originals in `public/` | Strips payloads, enforces authz (AC-83: "no one else") |
| D21 | IDs | UUIDv7 (`uuidv7()`) | bigint serial in URLs | Non-enumerable ids, index-friendly ordering; `deleted_[user_id]` uses the uuid |
| D22 | Scheduling | Worker process tick + advisory locks | System cron / supercronic | One container, no extra image; correctness never depends on tick timing |
| D23 | Availability storage | Per player profile, wall-clock times in org timezone, DB exclusion constraint | Per profile per Trainer | AC-61 says per profile; avoids parents entering the same week N times |
| D24 | Integrations | Ports with stub adapters (Epic-02/05/08) | Building minimal events/payments | Out of scope per spec |
| D25 | Runtime | FrankenPHP classic mode, Docker-only | FrankenPHP worker mode; php-fpm + nginx | Only local runtime; worker mode later after a state-leak review |

## Risks

- **Tenant leak by a missed `organization_id` filter.** Cheapest early check: the tenant-isolation Http suite from the first controller onward; code review checklist item; consider RLS later (D8).
- **SVG logo XSS.** Sanitizer + `<img>`-only rendering + strict CSP on media responses; add a test with known SVG payloads.
- **Brand color making text unreadable (AC-89).** `BrandPalette` picks contrasting text; unit-test against WCAG ratio.
- **Timezone ambiguity of availability and expiries.** Expiries are absolute `timestamptz`; availability uses org timezone - confirm with the client before Epic-02 scheduling relies on it.
- **AC-88 performance targets** (Users list 10k < 3 s, 1,000 concurrent) are unproven until a load test; seed 10k users in `app_test` and time the Users list query early.
- **Mailpit image not in local cache** (needs one pull); everything else is local.
- **Epic-02/05 contracts unknown.** Ports may need reshaping; keep them small and owned by the consumer module.
- **Argon2 memory cost under 1,000 concurrent logins.** Use default cost; measure with the load test.
- **Audit log vs AC-17 wording** (D14) may need client sign-off.

## AC and edge-case traceability

| ACs | Component(s) |
|---|---|
| AC-1, AC-2, AC-3, AC-4 | C4 login, throttle, Argon2id; C2 dashboards redirect |
| AC-5, AC-7 | C4 auth_tokens (1 h / 24 h), C14 emails |
| AC-6 | C4 Email VO + UNIQUE/CHECK |
| AC-8 | C2 SessionMiddleware, D3 |
| AC-9, AC-10 | C3 roles, policies, tenant scoping; C18 isolation suite |
| AC-11 | C2 CsrfMiddleware, D4 |
| AC-12..AC-17 | C5 CreateTrainer, C13 audit (AC-15 N/A by Q-01.08, `must_set_password` still enforced) |
| AC-18..AC-23, AC-26 | C6 share links, uses, flows; C5 coach invitations |
| AC-24, AC-25 | C2 ContextMiddleware, C7 context selector (content per context from Epic-02/04/05) |
| AC-27..AC-37 | C7 Family |
| AC-38..AC-45 | C8 Approval, C14 notifications/expiry, C17 payment port |
| AC-46..AC-49 | C9 matrix, C3 DenyChildLogin, C6 child block, C8 RSVP approval |
| AC-50..AC-56 | C12 impersonation, C2 ImpersonationMiddleware, C13 audit |
| AC-57..AC-60 | C5 coach invitations/memberships |
| AC-61..AC-67 | C10 Availability |
| AC-68..AC-71 | C7/C5 profile tables, `Phone` VO, C11 photos; read-only fields not bound in the profile DTO; email change in C12 |
| AC-72..AC-79 | C12 lifecycle, D15, C13 |
| AC-80 | C12 Users tool |
| AC-81..AC-84 | C5 branding, C11 images, C16 tokens |
| AC-85 | C6 prefill + SendShareLinkByEmail |
| AC-86 | C14 email catalogue |
| AC-87 | C13 append-only audit |
| AC-88 | Indexes (C15), outbox (D12), pagination (C12), load-test risk |
| AC-89 | C16 accessibility, BrandPalette contrast |

| Edge case (spec table) | Answered by |
|---|---|
| Duplicate email any case | C4 lowercase + UNIQUE, single transaction |
| Unknown code / deactivated or deleted Trainer link | C6 ResolveShareLink generic page |
| Coach link reused / after 7 days / coach active elsewhere / parallel invites | C5 derived status, row lock, partial unique index |
| Logged-in player already associated | C6 notice + redirect |
| Re-open link after parent removal | C6 new association only by parent choice; C7 history kept |
| Parent with no children / unticks everyone | C6 checklist rules |
| Child age out of range / similar child | C7 AddChild |
| Remove child with upcoming RSVPs | C7 confirm page + Epic-02 port via outbox |
| Child opens link / forbidden direct action | C6 child block; C9 + C3 |
| Approve after expiry / twice | C8 conditional UPDATE |
| Token setting changed while pending | C8 rule_snapshot |
| Payment hand-off fails | C8 `payment_failed` |
| Parent deactivated/deleted with pending requests | C12 cascade, C8 `auto_denied`, child login deactivated |
| Reset for unknown email / reused / after 1 h / newer link / sessions end | C4 tokens + session revocation |
| Verification after 24 h | C4 + resend |
| Rate limit exceeded with correct password | C4 throttle checked first |
| Session timeout mid-form | C2 RequireAuth return_to, POST not replayed |
| Trainer opens other Trainer's record | C3 tenant scoping -> 404 |
| Deactivated user with valid session | C2 AuthenticationMiddleware + C12 session deletion |
| Admin self-removal / last Super Admin | C12 locked checks |
| Delete Trainer with coaches/players | C12 refusal (Q-01.15) |
| Re-register original email after delete | C12 anonymized email frees original |
| Impersonate deactivated/deleted; 1 h; nested; browser closed | C12 + C2 ImpersonationMiddleware (`__Host-imp`), scheduler sweep |
| Coach slot end < start / overlap | C10 CHECK + EXCLUDE + validator |
| Override reason blank | C10 trim check + DB CHECK |
| Availability changed after assignment | C10 assignments untouched |
| No availability entries | C10 "no preference" |
| Logo bad / invalid hex | C5 + C11, old values kept |
| Two admins edit same user | C12 optimistic `version` |
| Photo upload fails midway | C11 separate processing |
| Prefill email already registered / tampered or expired | C6 prefill rules |

### Gaps - not fully answerable from this design

**Approved 2026-11-12 at spec-approval (user):** spec + design approved. G1 confirmed: the parent sets the child's login (username/email + password) on the child profile; children never self-register. G5 confirmed: IDs + business name only. G7 assumptions all accepted as written. G2/G3/G4/G6 stand as documented limits.

- **G1. Child login creation** has no AC; C7 assumes the parent enables it with email + password. Needs confirmation.
- **G2. AC-32 vs AC-61:** AC-32 says availability "per Trainer", AC-61 "per player profile". Design follows AC-61 (D23); per-Trainer calendar/RSVP/attendance belong to Epic-02.
- **G3. AC-24, AC-41, AC-46, AC-48, AC-67** depend on events/payments/tokens/content from Epic-02/04/05: Epic-01 delivers context, approval workflow and ports; end-to-end behavior is verifiable only with stubs.
- **G4. AC-74, AC-77** "analytics" do not exist yet; design guarantees data retention (no hard delete), not analytics views.
- **G5. AC-17 "Trainer's details"** recorded as IDs + business name, not personal fields (D14) - confirm.
- **G6. AC-88** load/concurrency targets are a measurement, not a design property.
- **G7. Assumptions to confirm:** password policy (10-128 chars), absolute session lifetime 30 days, trainer setup link 7 days, prefill link 14 days, "info requested" does not pause the 48 h clock, impersonating an inactive user is read-only.
