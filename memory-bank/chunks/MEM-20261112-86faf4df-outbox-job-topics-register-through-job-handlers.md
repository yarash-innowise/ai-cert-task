---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-86faf4df",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "config/container.php",
      "sha256": "69753e3027db94a9143b9a88d187994fb4f6fa3e9a1716c2cbaaa41cf7f7877a"
    },
    {
      "path": "project-brain/dynamic/decisions/86faf4df-2603-43c1-aa7e-4980e525c63e.md",
      "sha256": "d95e9966bb8b4b4d092e9fc76d5de4d32043a27bdfc7298bcb994c44b556b9dd"
    }
  ],
  "sources": [
    "config/container.php",
    "project-brain/dynamic/decisions/86faf4df-2603-43c1-aa7e-4980e525c63e.md"
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
  "title": "Outbox job topics register through job.handlers",
  "type": "decision",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# Outbox job topics register through job.handlers

New async jobs add a [topic, serviceId] pair to the job.handlers DI list (DI\add) in their module config/container.d file; JobOutboxHandler dispatches by topic.

## Sources
- config/container.php
