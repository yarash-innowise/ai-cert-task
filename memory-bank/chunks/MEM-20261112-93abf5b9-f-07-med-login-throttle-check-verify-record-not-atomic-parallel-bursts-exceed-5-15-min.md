---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-93abf5b9",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/93abf5b9-c1df-4868-9354-02bffa18026f.md",
      "sha256": "b798ce27dd38e1be222aabfceac52cb2d528ac1c3ae02d763c2f4b7cd9b4b1fd"
    },
    {
      "path": "tasks/TASK-001/code-reviewer-findings.md",
      "sha256": "28781f0f9582dc98f3032501c65591db48fe3d696bf855bb15e637d0dee01dfa"
    }
  ],
  "sources": [
    "project-brain/archive/finding/93abf5b9-c1df-4868-9354-02bffa18026f.md",
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
  "title": "F-07 [Med] Login throttle check/verify/record not atomic; parallel bursts exceed 5/15 min",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# F-07 [Med] Login throttle check/verify/record not atomic; parallel bursts exceed 5/15 min

fixed in review round R1; verified by full phpunit 997 green, phpstan L8, cs clean

## Sources
- tasks/TASK-001/code-reviewer-findings.md
