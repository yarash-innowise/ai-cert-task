---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-ca323135",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "project-brain/dynamic/decisions/ca323135-ca7a-4d5b-b2ac-7df19c16506a.md",
      "sha256": "b47a854b3e20864cc0f9b693070a84863330933b33ecf3d04a25a301373c09f7"
    },
    {
      "path": "src/Shared/Infrastructure/Outbox/OutboxWorker.php",
      "sha256": "a09a506c8c2dba8c407135143e144674207ab63f47f8119136cdc83f534d7dcb"
    }
  ],
  "sources": [
    "project-brain/dynamic/decisions/ca323135-ca7a-4d5b-b2ac-7df19c16506a.md",
    "src/Shared/Infrastructure/Outbox/OutboxWorker.php"
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
  "title": "Outbox worker uses leased per-row claiming",
  "type": "decision",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# Outbox worker uses leased per-row claiming

OutboxWorker claims rows as processing with locked_until lease in a short committed transaction, then runs each handler with no open transaction; handlers needing atomicity open their own TransactionManager run and must be idempotent by outbox id. Email payloads are scrubbed after send.

## Sources
- src/Shared/Infrastructure/Outbox/OutboxWorker.php
