---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-7009aade",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/archive/finding/7009aade-b748-49a2-ab39-82829bbcbf10.md",
      "sha256": "a6864931dd4e86813ab2457d993a39ef3ae275cc39432c3abb5be916e2e2f05a"
    },
    {
      "path": "specs/requirements-analyst-user-management-auth-spec.md",
      "sha256": "4cf429b0ca9340df9b0f2c3e410f53f8ca4cc1ba7958bcd9800dadc335a4a95d"
    }
  ],
  "sources": [
    "project-brain/archive/finding/7009aade-b748-49a2-ab39-82829bbcbf10.md",
    "specs/requirements-analyst-user-management-auth-spec.md"
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
  "title": "Spec conflict on Trainer deletion blockers",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# Spec conflict on Trainer deletion blockers

Edge-case row says deletion refused while Trainer has coaches and players; accepted Q-01.15 default says only active coaches block. Implementation follows Q-01.15; needs user confirmation before closing.

## Sources
- specs/requirements-analyst-user-management-auth-spec.md
