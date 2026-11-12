---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-db0a5b09",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/db0a5b09-7174-41ae-86c1-5baeec79d1fb.md",
      "sha256": "443ef592f7c003c8957819c5fce1bf25f145465539b9d899c63ace55d154be1c"
    },
    {
      "path": "tasks/TASK-001/code-reviewer-findings.md",
      "sha256": "28781f0f9582dc98f3032501c65591db48fe3d696bf855bb15e637d0dee01dfa"
    }
  ],
  "sources": [
    "project-brain/archive/finding/db0a5b09-7174-41ae-86c1-5baeec79d1fb.md",
    "tasks/TASK-001/code-reviewer-findings.md"
  ],
  "status": "active",
  "superseded_by": null,
  "supersedes": [],
  "tags": [
    "project-brain",
    "promoted",
    "auto-promoted"
  ],
  "title": "F-08 [Med] Unknown-email login computes a fresh Argon2 dummy hash per request -> ~2x latency reveals account existence",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# F-08 [Med] Unknown-email login computes a fresh Argon2 dummy hash per request -> ~2x latency reveals account existence

fixed in review round R1; verified by full phpunit 997 green, phpstan L8, cs clean

## Sources
- tasks/TASK-001/code-reviewer-findings.md
