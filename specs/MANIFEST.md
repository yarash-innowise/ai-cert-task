# Project: [Project Name]

A multi-role sports-training platform that connects Super Admins, Trainers, Coaches and Players/Parents. The first foundation epic (Epic-01) defines accounts, authentication, role-based access, Trainer-organization data isolation, parent/child family accounts, invitations by share link, availability and impersonation, on which all other epics build. Implementation is native PHP (Composer + PSR); the stack and design are decided later by architecture.

## Specs Index

| File | Purpose | Depends On | Last Updated |
|------|---------|------------|--------------|
| architect-architecture.md | System design, components, data flow | - | - |
| api-designer-spec.md | Endpoints, schemas, authentication | architect-architecture | - |
| frontend-design-spec.md | Pages, components, state management | architect-architecture, api-designer-spec | - |
| docs-generator-implementation.md | Build process, deployment, tooling | - | - |
| requirements-analyst-user-management-auth-spec.md | Epic-01 what/why: authentication, 4 roles, share-link onboarding, parent/child accounts, availability, impersonation, deactivate/delete, branding (AC-1..AC-89) | - | 2026-11-12 |
| architect-user-management-auth-architecture.md | Epic-01 how: modules, PSR-15 pipeline (session, CSRF, auth, impersonation, tenant context), PostgreSQL schema, outbox/worker, file storage, Docker setup, decisions D1-D25, AC/edge traceability, gaps G1-G7 | requirements-analyst-user-management-auth-spec | 2026-11-12 |

## Key Decisions

- Backend: native PHP 8.4 on FrankenPHP (classic mode), single PSR-4 `src/` split into modules (Identity, Organization, Onboarding, Family, Approval, Availability, Admin, Shared) with Domain/Application/Infrastructure/Http inside
- HTTP: front controller + league/route + PSR-7/15 middleware pipeline; thin controllers, use cases, PDO repositories
- Auth: server-side sessions stored in PostgreSQL (revocable), Argon2id, synchronizer-token CSRF, DB-backed login throttle (5/15 min per email or IP)
- Tenancy: Trainer organization = tenant; explicit `organization_id` scoping in every tenant query, 404 on mismatch; RLS rejected for now
- Side effects: transactional outbox + worker/scheduler process (emails, payment hand-off, expiries)
- Audit log append-only (grants + trigger), IDs only, so GDPR anonymization never edits it
- Other epics (events, payments, camp forms) integrated via ports with stub adapters
- Details and rejected alternatives: `architect-user-management-auth-architecture.md`

## Tech Stack

- PHP 8.4 (`dunglas/frankenphp:1-php8.4`), PostgreSQL 18 (`postgres:18`), Mailpit (dev), Docker Compose only (no host PHP/Composer)
- league/route ^6.2, nyholm/psr7 ^1.8 + psr7-server ^1.1, laminas-httphandlerrunner ^2.12, php-di ^7.0, twig ^3.21, robmorgan/phinx ^0.16, symfony/mailer ^7.3, libphonenumber-for-php-lite ^9.0, enshrined/svg-sanitize ^1.1 (0.21 blocked by advisories), ext-gd
- Dev: PHPUnit ^12, PHPStan ^2.1 (level 8), PHP-CS-Fixer ^3 (PER-CS)

---

*This manifest is updated automatically by architect, api-designer, and frontend-design skills.*
*See `../spec-desc.md` for specification structure guidelines.*
