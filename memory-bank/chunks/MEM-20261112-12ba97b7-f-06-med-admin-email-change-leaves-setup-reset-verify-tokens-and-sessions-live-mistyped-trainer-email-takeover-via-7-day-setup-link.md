---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-12ba97b7",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/12ba97b7-378a-468b-954f-0084d6812bea.md",
      "sha256": "d7f9f6b1e36840b7decfc9e6a91387edc6bd8bbfb68b706880f3d3d7d60d9e8d"
    },
    {
      "path": "tasks/TASK-001/code-reviewer-findings.md",
      "sha256": "28781f0f9582dc98f3032501c65591db48fe3d696bf855bb15e637d0dee01dfa"
    }
  ],
  "sources": [
    "project-brain/archive/finding/12ba97b7-378a-468b-954f-0084d6812bea.md",
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
  "title": "F-06 [Med] Admin email change leaves setup/reset/verify tokens and sessions live (mistyped Trainer email -> takeover via 7-day setup link)",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# F-06 [Med] Admin email change leaves setup/reset/verify tokens and sessions live (mistyped Trainer email -> takeover via 7-day setup link)

fixed in review round R1; verified by full phpunit 997 green, phpstan L8, cs clean

## Sources
- tasks/TASK-001/code-reviewer-findings.md
