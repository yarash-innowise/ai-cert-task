---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-201f0328",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/dynamic/decisions/201f0328-884e-438a-85cc-8de37b6e2fca.md",
      "sha256": "6c7e81326957ebc4716c3072e377fc72c1a23f8bb8b57c847ad4fc919f006da0"
    },
    {
      "path": "src/Family/Domain/ChildCapabilities.php",
      "sha256": "7eb0219bcc13e77c2c5d8a64f8cf04f62cf444e8c42b08f69a98496abcc0d9fa"
    },
    {
      "path": "src/Identity/Http/DenyChildLoginMiddleware.php",
      "sha256": "709b9b80f50051b595b5736aea24ccc1ed56aa1eecb3c98c497db4a31f66d2fc"
    }
  ],
  "sources": [
    "project-brain/dynamic/decisions/201f0328-884e-438a-85cc-8de37b6e2fca.md",
    "src/Family/Domain/ChildCapabilities.php",
    "src/Identity/Http/DenyChildLoginMiddleware.php"
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
  "title": "Child logins are refused by DenyChildLoginMiddleware with 403",
  "type": "decision",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# Child logins are refused by DenyChildLoginMiddleware with 403

Forbidden child actions return 403 (not 404) via DenyChildLoginMiddleware plus ChildCapabilities; new parent-only family routes must be added to that guard, and reserved routes exist for payment methods, token purchase, purchase completion and account deletion.

## Sources
- src/Family/Domain/ChildCapabilities.php
- src/Identity/Http/DenyChildLoginMiddleware.php
