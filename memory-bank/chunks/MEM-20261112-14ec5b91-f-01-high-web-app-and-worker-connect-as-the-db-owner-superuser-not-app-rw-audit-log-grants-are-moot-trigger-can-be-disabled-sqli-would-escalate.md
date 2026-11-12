---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-14ec5b91",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/14ec5b91-d797-4102-b074-89d34aa1dae3.md",
      "sha256": "615ba49a39c5beead6af655cbf3810ced4a3321bddeb5e7ae9e9b2708da08f7e"
    },
    {
      "path": "tasks/TASK-001/code-reviewer-findings.md",
      "sha256": "28781f0f9582dc98f3032501c65591db48fe3d696bf855bb15e637d0dee01dfa"
    }
  ],
  "sources": [
    "project-brain/archive/finding/14ec5b91-d797-4102-b074-89d34aa1dae3.md",
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
  "title": "F-01 [High] Web app and worker connect as the DB owner/superuser, not `app_rw`; audit-log grants are moot, trigger can be disabled; SQLi would escalate",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# F-01 [High] Web app and worker connect as the DB owner/superuser, not `app_rw`; audit-log grants are moot, trigger can be disabled; SQLi would escalate

Fixed in review round R1; tests green

## Sources
- tasks/TASK-001/code-reviewer-findings.md
