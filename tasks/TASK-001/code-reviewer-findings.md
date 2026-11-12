# Epic-01 review findings (merged: code-reviewer + security-reviewer, 2026-11-12)

Deduplicated from the two phase-6 reports. Severity = the higher of the two reviewers. Status is updated as fixes land.

| ID | Sev | Finding | Spec link | Key files | Status |
|---|---|---|---|---|---|
| F-01 | High | Web app and worker connect as the DB owner/superuser, not `app_rw`; audit-log grants are moot, trigger can be disabled; SQLi would escalate | AC-87, design C13/C15 | `config/settings.php`, `config/container.php`, `docker-compose.yml` | **fixed R1** |
| F-02 | High | GDPR delete leaves PII in `outbox.payload`, `coach_invitations` (email/name/message), `notifications`, `registration_prefills.data`, user-authored free-text notes; `login_attempts` only by exact email | AC-76, AC-79, Q-01.13 | `src/Admin/Infrastructure/PdoUserLifecycleStore.php` | **fixed R3** (residuals: deleted adult self-profile keeps association; org business_name/details kept; gender/skill_level kept; unlinked mentions in others\' notifications) |
| F-03 | Med | Parent deletion leaves child profiles / child-login users intact | AC-76, Q-01.14 | same | **fixed R3** |
| F-04 | Med | `GET /join/{code}` (and prefill attach GET) changes state: cross-site forced join under SameSite=Lax; bypasses impersonation read-only + audit; repeated child-block emails | AC-11, AC-21, AC-49, AC-55, Q-01.16 | `src/Onboarding/Http/JoinController.php`, `PrefillRegisterController.php`, `BlockChildLink.php` | **fixed R4** |
| F-05 | Med | Plaintext one-time links + PII kept in `outbox.payload` forever; no outbox purge | D7, Q-01.13 | `src/Shared/Infrastructure/Outbox/*` | **fixed R2** |
| F-06 | Med | Admin email change leaves setup/reset/verify tokens and sessions live (mistyped Trainer email -> takeover via 7-day setup link) | AC-5, AC-14 | `src/Admin/Application/EditUser.php` | **fixed R1** |
| F-07 | Med | Login throttle check/verify/record not atomic; parallel bursts exceed 5/15 min | AC-3 | `src/Identity/Application/LoginUser.php`, `PdoLoginThrottle.php` | **fixed R1** |
| F-08 | Med | Unknown-email login computes a fresh Argon2 dummy hash per request -> ~2x latency reveals account existence | AC-2, design C4 | `src/Identity/Domain/PasswordHasher.php` | **fixed R1** |
| F-09 | Med | Coach memberships never end (deleted/deactivated Trainer keeps coach "active" -> AC-59 dead end); no reassign; accept vs delete race (no org lock) | Q-01.15, AC-59 | `PdoCoachMemberships.php`, `PdoCoachInvitationRepository.php`, `DeleteUser.php` | **fixed R4** (old-Trainer reactivation does not restore an ended coach membership) |
| F-10 | Med | Outbox worker runs whole batch in one transaction: crash re-runs sent emails and payment hand-off; hand-off not outside a transaction | AC-41, design C8/D12 | `src/Shared/Infrastructure/Outbox/OutboxWorker.php` | **fixed R2** |
| F-11 | Med | Child purchase amount/event taken from the client form and passed to the payment port | AC-38, AC-41 | `src/Approval/Http/NewRequestForm.php` | **fixed R4** (token-spend count still child-entered; checked by TokenSpend balance) |
| F-12 | Low | No rate limit on forgot-password / verification resend | AC-5, AC-7 | `PasswordResetController.php`, `EmailVerificationController.php` | open |
| F-13 | Low | Race tests disable all triggers (`session_replication_role=replica`) and leak rows on assertion failure | DoD test hygiene | `tests/Integration/{Organization/ConcurrentAcceptTest,Approval/DecideRaceTest}.php` | open |
| F-14 | Low | Trainer with expired setup link has no recovery (reset leaves email unverified) | AC-14 | Admin / Identity | open |
| F-15 | Low | Parent can assign any email to a child login, marked verified, with no mailbox proof | G1 | `src/Family/Application/EnableChildLogin.php` | open |
| F-16 | Low | Staged branding previews in `var/storage/tmp` never purged, not bound to org | AC-81 | `ImageStore.php`, `BrandingController.php` | open |
| F-17 | Low | Child login may edit name/phone/school/jersey via /profile; AC-46 says "basic profile info (photo, preferences)" | AC-46 | `src/Identity/Application/EditOwnProfile.php` | **resolved by decision** (AC-46 amended; fields allowed) |
| F-18 | Info | Dev-default DB passwords in compose must fail closed in prod; error log may include PII from PDOException messages | - | `docker-compose.yml`, `ErrorHandlerMiddleware.php` | open |

Verified sound by both reviewers: prepared SQL, Argon2id, server-side sessions + regeneration, CSRF (session + guest double-submit + Origin), ReturnTo, token hashing/single use/lifetimes, tenant isolation (404), child deny matrix, impersonation core, uploads/SVG/media authorization, Twig autoescape + CSP, composer audit clean.
