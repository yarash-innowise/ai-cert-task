---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-939b6ade",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/939b6ade-5899-4450-8d4e-c1ce66ff0273.md",
      "sha256": "aa6b55c680e662e29d82ba59aab35a2665b9158249c18c4413b785a835ba37b2"
    },
    {
      "path": "tasks/TASK-001/code-reviewer-findings.md",
      "sha256": "28781f0f9582dc98f3032501c65591db48fe3d696bf855bb15e637d0dee01dfa"
    }
  ],
  "sources": [
    "project-brain/archive/finding/939b6ade-5899-4450-8d4e-c1ce66ff0273.md",
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
  "title": "F-02 [High] GDPR delete leaves PII in `outbox.payload`, `coach_invitations` (email/name/message), `notifications`, `registration_prefills.data`, user-authored free-text notes; `login_attempts`",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# F-02 [High] GDPR delete leaves PII in `outbox.payload`, `coach_invitations` (email/name/message), `notifications`, `registration_prefills.data`, user-authored free-text notes; `login_attempts`

fixed in review round R3; verified by full phpunit 997 green, phpstan L8, cs clean

## Sources
- tasks/TASK-001/code-reviewer-findings.md
