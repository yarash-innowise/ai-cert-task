---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-d688c8b0",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/d688c8b0-2759-4e8f-a807-2a3e67e6fcdb.md",
      "sha256": "fe7bd926f2b01c6187cff71d4857d3477f548003335e432c0c13000bb8200f89"
    },
    {
      "path": "tasks/TASK-001/code-reviewer-findings.md",
      "sha256": "28781f0f9582dc98f3032501c65591db48fe3d696bf855bb15e637d0dee01dfa"
    }
  ],
  "sources": [
    "project-brain/archive/finding/d688c8b0-2759-4e8f-a807-2a3e67e6fcdb.md",
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
  "title": "F-05 [Med] Plaintext one-time links + PII kept in `outbox.payload` forever; no outbox purge",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# F-05 [Med] Plaintext one-time links + PII kept in `outbox.payload` forever; no outbox purge

fixed in review round R2; verified by full phpunit 997 green, phpstan L8, cs clean

## Sources
- tasks/TASK-001/code-reviewer-findings.md
