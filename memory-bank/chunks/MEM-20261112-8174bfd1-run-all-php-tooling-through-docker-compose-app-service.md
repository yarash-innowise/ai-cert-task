---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-8174bfd1",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "docker-compose.yml",
      "sha256": "8a943b07b762b2a5a5fa9b0bdb38a19494ec6e952257c92d911bbce881d24bb2"
    },
    {
      "path": "project-brain/archive/finding/8174bfd1-7d7d-4511-bcf6-d62452209503.md",
      "sha256": "fcc071fea204a4a461dd43a27df5882ca79eccb564bd3e2419e2e0489391c160"
    },
    {
      "path": "tasks/TASK-001/writing-plans-plan.md",
      "sha256": "e21320436ca984fd62a4b627db4aebbb739587467f90f1bf19f664c0acbd2cbd"
    }
  ],
  "sources": [
    "docker-compose.yml",
    "project-brain/archive/finding/8174bfd1-7d7d-4511-bcf6-d62452209503.md",
    "tasks/TASK-001/writing-plans-plan.md"
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
  "title": "Run all PHP tooling through docker compose app service",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# Run all PHP tooling through docker compose app service

Host has no PHP; checks run as docker compose run --rm app vendor/bin/{phpunit,phpstan,php-cs-fixer}; host ports 80/443/8025 are taken so app uses 18443/18080 and mailpit UI 8125.

## Sources
- docker-compose.yml
- tasks/TASK-001/writing-plans-plan.md
