---
{
  "created": "2026-11-12",
  "id": "MEM-20261112-a12087cf",
  "last_verified": "2026-11-12",
  "review_after": "2027-11-12",
  "scope": [
    "application"
  ],
  "source_digests": [
    {
      "path": "phpstan.neon",
      "sha256": "a3882ad40b2e7366715333492eab2d89b9942d3a1cd3196d45f37678d75bda3d"
    },
    {
      "path": "project-brain/dynamic/decisions/a12087cf-0a2e-4109-9a85-3e0513aa0304.md",
      "sha256": "7808aa1f1c8faff54d30c8d1d8b989280a77d75381fb5f71c8e46cb4ab95f164"
    },
    {
      "path": "tests/Support/PhpStan/ModuleBoundaryRule.php",
      "sha256": "c9e7691fcd236b0bbdc92a318e314fae761d22d11b389c746164959ba92d6684"
    }
  ],
  "sources": [
    "phpstan.neon",
    "project-brain/dynamic/decisions/a12087cf-0a2e-4109-9a85-3e0513aa0304.md",
    "tests/Support/PhpStan/ModuleBoundaryRule.php"
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
  "title": "PHPStan forbids cross-module Infrastructure imports",
  "type": "decision",
  "valid_from": "2026-11-12",
  "valid_to": null
}
---

# PHPStan forbids cross-module Infrastructure imports

ModuleBoundaryRule (tests/Support/PhpStan) fails analysis when a module imports another module Infrastructure namespace; Shared Infrastructure is allowed. Cross-module calls go through Application/Domain ports.

## Sources
- phpstan.neon
- tests/Support/PhpStan/ModuleBoundaryRule.php
