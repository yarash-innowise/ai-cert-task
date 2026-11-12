---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-a6e15a6a",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/a6e15a6a-c427-4034-b0c7-e269d56c5de0.md",
      "sha256": "dfb95c7a100f5370b2f60a7699d5c7750ed831dfd8b017c3bc89c1465b43b515"
    },
    {
      "path": "tasks/TASK-001/code-reviewer-findings.md",
      "sha256": "28781f0f9582dc98f3032501c65591db48fe3d696bf855bb15e637d0dee01dfa"
    }
  ],
  "sources": [
    "project-brain/archive/finding/a6e15a6a-c427-4034-b0c7-e269d56c5de0.md",
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
  "title": "F-04 [Med] `GET /join/{code}` (and prefill attach GET) changes state: cross-site forced join under SameSite=Lax; bypasses impersonation read-only + audit; repeated child-block emails",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# F-04 [Med] `GET /join/{code}` (and prefill attach GET) changes state: cross-site forced join under SameSite=Lax; bypasses impersonation read-only + audit; repeated child-block emails

fixed in review round R4; verified by full phpunit 997 green, phpstan L8, cs clean

## Sources
- tasks/TASK-001/code-reviewer-findings.md
