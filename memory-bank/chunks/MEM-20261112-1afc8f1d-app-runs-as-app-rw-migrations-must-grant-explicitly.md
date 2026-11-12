---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-1afc8f1d",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "db/migrations/20261112180000_app_rw_least_privilege.php",
      "sha256": "d33cf5a29e26f23b2cc0b03f3bae93e86c06b82d3bee3f74e915360f39d1abe0"
    },
    {
      "path": "docker-compose.yml",
      "sha256": "bba8101cf826732504a9874ac35f534ba931440d60c308b5fd4d1cf9e7e58c91"
    },
    {
      "path": "phinx.php",
      "sha256": "d71b226b4e7398fe7ce3aa462b2760cb6b611212088bc83bd5c22316a33bbbca"
    },
    {
      "path": "project-brain/dynamic/decisions/1afc8f1d-b5fa-42f1-941a-e2ba829fc5c2.md",
      "sha256": "fea2f9f7bcb5117454a2cb3cd9ef40f5bad9237e11302bfcb6b6da32abb1d7a1"
    }
  ],
  "sources": [
    "db/migrations/20261112180000_app_rw_least_privilege.php",
    "docker-compose.yml",
    "phinx.php",
    "project-brain/dynamic/decisions/1afc8f1d-b5fa-42f1-941a-e2ba829fc5c2.md"
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
  "title": "App runs as app_rw; migrations must grant explicitly",
  "type": "decision",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# App runs as app_rw; migrations must grant explicitly

Web app and worker connect as least-privilege app_rw; phinx uses DB_OWNER_USER. No default table privileges exist, so every new migration must GRANT the needed privileges to app_rw (audit-style tables INSERT/SELECT only). Tests connect as app_rw; use connectAsOwner() only for teardown that must bypass triggers.

## Sources
- db/migrations/20261112180000_app_rw_least_privilege.php
- docker-compose.yml
- phinx.php
