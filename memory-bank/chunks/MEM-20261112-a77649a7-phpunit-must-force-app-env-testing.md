---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-a77649a7",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "phpunit.xml.dist",
      "sha256": "e9db86cb3ae8c3e22c02bd40dae2516c5d6eefdc71ae8305cd704295a6a77b86"
    },
    {
      "path": "project-brain/archive/finding/a77649a7-2c97-44be-bac6-310412e1f542.md",
      "sha256": "9548710c294e824e302f52ed6ac5384d997a682dcd670f1a2a3eb8ccd525ce77"
    }
  ],
  "sources": [
    "phpunit.xml.dist",
    "project-brain/archive/finding/a77649a7-2c97-44be-bac6-310412e1f542.md"
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
  "title": "PHPUnit must force APP_ENV=testing",
  "type": "domain",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# PHPUnit must force APP_ENV=testing

Without <env name=APP_ENV value=testing force=true/> in phpunit.xml.dist, HTTP tests ran against the dev database app instead of app_test; keep the forced env.

## Sources
- phpunit.xml.dist
