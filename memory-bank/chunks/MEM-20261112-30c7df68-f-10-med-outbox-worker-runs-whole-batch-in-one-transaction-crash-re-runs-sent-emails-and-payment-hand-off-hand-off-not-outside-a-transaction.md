---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-30c7df68",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/30c7df68-93ec-4e54-a47a-fa68d735b5c4.md",
      "sha256": "4e160b2680c72e7f6bbb86066c1131cc95c343d4b9bed454dcd466139feb6b60"
    },
    {
      "path": "tasks/TASK-001/code-reviewer-findings.md",
      "sha256": "28781f0f9582dc98f3032501c65591db48fe3d696bf855bb15e637d0dee01dfa"
    }
  ],
  "sources": [
    "project-brain/archive/finding/30c7df68-93ec-4e54-a47a-fa68d735b5c4.md",
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
  "title": "F-10 [Med] Outbox worker runs whole batch in one transaction: crash re-runs sent emails and payment hand-off; hand-off not outside a transaction",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# F-10 [Med] Outbox worker runs whole batch in one transaction: crash re-runs sent emails and payment hand-off; hand-off not outside a transaction

fixed in review round R2; verified by full phpunit 997 green, phpstan L8, cs clean

## Sources
- tasks/TASK-001/code-reviewer-findings.md
