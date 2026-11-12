---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-ce55d7d6",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/dynamic/decisions/ce55d7d6-1077-41cc-a701-4288a9f14792.md",
      "sha256": "e5ba9fd2d64d2184d249ea93ead0dda1da34dbe3788b7ec19ce87d5b3e754598"
    },
    {
      "path": "tests/Http/TenantIsolation/TenantRouteRegistry.php",
      "sha256": "d9b8508bccdf1a1efdb03c4284cc3d923893400626159edfceccbb4c6c10b449"
    }
  ],
  "sources": [
    "project-brain/dynamic/decisions/ce55d7d6-1077-41cc-a701-4288a9f14792.md",
    "tests/Http/TenantIsolation/TenantRouteRegistry.php"
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
  "title": "Every tenant route must register in the isolation harness",
  "type": "decision",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# Every tenant route must register in the isolation harness

tests/Http/TenantIsolation/TenantRouteRegistry must classify each route as tenant or non-tenant; the harness fails on unclassified routes and expects 404 for foreign-organization access.

## Sources
- tests/Http/TenantIsolation/TenantRouteRegistry.php
