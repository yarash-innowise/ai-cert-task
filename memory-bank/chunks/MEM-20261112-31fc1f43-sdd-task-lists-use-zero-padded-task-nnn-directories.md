---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-31fc1f43",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "AGENTS.md",
      "sha256": "47a0de56517e0bcd187f6c93ac604761ed715ca2a9a41b98089000440f16ddf0"
    },
    {
      "path": "project-brain/dynamic/decisions/31fc1f43-aa33-47c4-b203-a8a7f92505f4.md",
      "sha256": "ed86bc7244627aa3ced00b37033b14e8f40381e06a06cd1edf21927a9535e1c9"
    },
    {
      "path": "tasks/README.md",
      "sha256": "146c9052390cd65ecb423f082db83e839eb936650be50cb8a5e098ac18a7d1af"
    }
  ],
  "sources": [
    "AGENTS.md",
    "project-brain/dynamic/decisions/31fc1f43-aa33-47c4-b203-a8a7f92505f4.md",
    "tasks/README.md"
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
  "title": "SDD task lists use zero-padded TASK-NNN directories",
  "type": "decision",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# SDD task lists use zero-padded TASK-NNN directories

AGENTS.md requires tasks/TASK-NNN/ from tasks/.task-counter, overriding the sdd template path tasks/<TASK-ID>/; Epic-01 plan lives in tasks/TASK-001/.

## Sources
- AGENTS.md
- tasks/README.md
