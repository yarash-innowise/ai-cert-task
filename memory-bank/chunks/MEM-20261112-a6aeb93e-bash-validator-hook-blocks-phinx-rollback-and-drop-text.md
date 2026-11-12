---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-a6aeb93e",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": ".claude/hooks/bash-validator.sh",
      "sha256": "f731e879593d0cebba1051722e860a58b9a53702bb618cb76300557bfc3be849"
    },
    {
      "path": "project-brain/archive/finding/a6aeb93e-3b3e-4f2f-9445-55b9bc0d2b97.md",
      "sha256": "5da2ce95c364db1bc08c7b119a23b7bcb43933ddfb666481c12522e6c89177d0"
    }
  ],
  "sources": [
    ".claude/hooks/bash-validator.sh",
    "project-brain/archive/finding/a6aeb93e-3b3e-4f2f-9445-55b9bc0d2b97.md"
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
  "title": "bash-validator hook blocks phinx rollback and DROP text",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# bash-validator hook blocks phinx rollback and DROP text

Migration down() round trips cannot be run by agents via Bash; write migrations with the Write tool and ask the user to run rollback checks.

## Sources
- .claude/hooks/bash-validator.sh
