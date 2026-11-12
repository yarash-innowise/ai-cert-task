---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-6a0df072",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "config/middleware.php",
      "sha256": "bb99b798f268ecb94cdc2734256da66262423736cddd6dea41acb6a2c60f1087"
    },
    {
      "path": "project-brain/dynamic/decisions/6a0df072-7dee-4f4a-9975-eb8932902756.md",
      "sha256": "f563dbe3287fe89613f63724f290c223b8a66cf1761efc438e389f29a0dd7d23"
    },
    {
      "path": "tests/Http/HttpTestCase.php",
      "sha256": "ab33fcee7a2291154a9d8ee60f18ea1ca61c5c2d3f1a07f0bd9d1ee13dbf16f6"
    }
  ],
  "sources": [
    "config/middleware.php",
    "project-brain/dynamic/decisions/6a0df072-7dee-4f4a-9975-eb8932902756.md",
    "tests/Http/HttpTestCase.php"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted",
    "agent-attested"
  ],
  "title": "Guest forms use a double-submit CSRF cookie",
  "type": "decision",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# Guest forms use a double-submit CSRF cookie

sessions rows require a user, so unauthenticated forms (login, reset, resend) use a __Host-csrf double-submit cookie plus Origin/Referer check; signed-in users use the per-session synchronizer token. Tests post guest forms via HttpTestCase guestCsrf().

## Sources
- config/middleware.php
- tests/Http/HttpTestCase.php
