---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-43486df8",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/dynamic/decisions/43486df8-1245-495a-90b2-4578d88a78f7.md",
      "sha256": "025c78460649897f2d0bb6daedf4eb2016d905b6277f823b1915f8772e22ca67"
    },
    {
      "path": "src/Integration/Application/EventCatalog.php",
      "sha256": "e8b4e96198bee45164c182e796778d630fbf5de5dcd4be94fc2220d6b52d36c1"
    }
  ],
  "sources": [
    "project-brain/dynamic/decisions/43486df8-1245-495a-90b2-4578d88a78f7.md",
    "src/Integration/Application/EventCatalog.php"
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
  "title": "Approval requests resolve event and price via EventCatalog",
  "type": "decision",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# Approval requests resolve event and price via EventCatalog

Child purchase/RSVP requests never trust client-sent title, amount or currency; ResolveRequestedEvent looks the event up through the organization-scoped EventCatalog port and refuses unknown or foreign events. Epic-02/05 must supply EventCatalog with prices.

## Sources
- src/Integration/Application/EventCatalog.php
