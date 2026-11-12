---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-21912a37",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "config/middleware.php",
      "sha256": "1d9224324538ffd12b6c233d9144d40f67ca4340d7ece2001684998b329d643f"
    },
    {
      "path": "project-brain/archive/finding/21912a37-df12-4f0c-9fa8-4df62bd859fe.md",
      "sha256": "8f4be315e3ccfdc1051276391ace6aec6222c229baafe20c09630049d2f778cb"
    }
  ],
  "sources": [
    "config/middleware.php",
    "project-brain/archive/finding/21912a37-df12-4f0c-9fa8-4df62bd859fe.md"
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
  "title": "Security headers middleware must wrap the error handler",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# Security headers middleware must wrap the error handler

SecurityHeadersMiddleware is outermost and ErrorHandlerMiddleware inside it so 403/404/500 pages also carry CSP/HSTS; keep this order when adding middleware.

## Sources
- config/middleware.php
