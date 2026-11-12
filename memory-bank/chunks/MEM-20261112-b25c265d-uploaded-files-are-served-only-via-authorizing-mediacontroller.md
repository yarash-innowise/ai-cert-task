---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-b25c265d",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/dynamic/decisions/b25c265d-2401-43d0-96bc-5f92050a0230.md",
      "sha256": "93fb51c5d64381542b22df3c1f4162bc3579c2f767a95b9f06eb7ba5c2ca3a11"
    },
    {
      "path": "src/Shared/Http/MediaController.php",
      "sha256": "d63e286edfa4bca583de9128e581caedbbc1f247e8dfb0ede9d2274d4b4faa8e"
    }
  ],
  "sources": [
    "project-brain/dynamic/decisions/b25c265d-2401-43d0-96bc-5f92050a0230.md",
    "src/Shared/Http/MediaController.php"
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
  "title": "Uploaded files are served only via authorizing MediaController",
  "type": "decision",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# Uploaded files are served only via authorizing MediaController

Photos and logos live in private storage and are served at /media/{kind}/{id} after PdoMediaAccess checks the viewer; new media kinds must add an access rule there rather than exposing public paths.

## Sources
- src/Shared/Http/MediaController.php
