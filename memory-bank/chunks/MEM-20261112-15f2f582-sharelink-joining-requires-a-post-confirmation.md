---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-15f2f582",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/dynamic/decisions/15f2f582-db5d-47f4-978e-5f2c228f3542.md",
      "sha256": "11c0223c005df0e4ce63e71d52d47299c8cd0b953aa2e30d4e054f0174d547b4"
    },
    {
      "path": "specs/requirements-analyst-user-management-auth-spec.md",
      "sha256": "d0d051b1a0f66249ca33a536494d36c7e0084214df2dce7fd6422d18976dfff8"
    }
  ],
  "sources": [
    "project-brain/dynamic/decisions/15f2f582-db5d-47f4-978e-5f2c228f3542.md",
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
  "title": "ShareLink joining requires a POST confirmation",
  "type": "decision",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# ShareLink joining requires a POST confirmation

Opening /join/{code} must not change state; association, child-block notification and prefill attach happen only via CSRF-protected POST (AC-21/AC-49 amended), so impersonation guards and audit apply.

## Sources
- specs/requirements-analyst-user-management-auth-spec.md
