# Accessibility and mobile verification, Epic-01 (task 43, AC-89)

Target: WCAG 2.1 AA, phone-sized screens (320 px). Run on 2026-11-12 against the dev stack.

## What was run

| Check | How | Result |
|---|---|---|
| Structural lint of rendered screens | `docker compose run --rm app vendor/bin/phpunit --filter AccessibilityLintTest` (tests/Http/AccessibilityLintTest.php). Renders public, player/parent, child-login, Trainer, Coach, Super Admin screens, form-error states and the impersonated view through the full middleware pipeline | pass (12 tests). Asserts per page: one `h1`, `lang`, title, skip link to `#main`, one banner and one contentinfo landmark, status region, no skipped heading level, unique ids, every `aria-describedby`/`aria-labelledby` and in-page link resolves, every control has a label, radio/checkbox groups sit in a fieldset with legend, `aria-invalid` fields are described, error summary is an alert that can take focus, images have `alt`, links/buttons have a name, no positive tabindex, tables have header cells, POST forms carry a CSRF field. Self-test proves the linter fails on known-bad markup |
| No escaping bypass | same test class, scans `templates/**` for `raw` filter / `autoescape false` | pass, none found |
| Focus, touch target, mobile CSS | same test class (stylesheet assertions) | pass: `:focus-visible` outline, 44 px controls, mobile-first CSS (only `min-width` queries), wide tables scroll inside `.table-wrap`, reduced motion honoured, viewport meta |
| Contrast of the design tokens | same test class, WCAG formula (`BrandPalette::contrastRatio`) | pass. Body text on page 17.5:1; hint text 5.8:1; default brand link 6.5:1; on-brand button text 7.2:1; error text on error summary 5.8:1; impersonation banner 13.8:1; input border 3.6:1 (needs 3:1); focus ring above 3:1. Branded colors are held to the black/white text rule by BrandPalette (existing tests) |
| Keyboard-only walk and 320 px layout in a real browser | `scripts/a11y/walk.cjs` in the Playwright container (`docker compose --profile a11y run --rm a11y`, image `mcr.microsoft.com/playwright:v1.64.1-noble`, nothing installed on the host). Presses Tab through each page at 320x640 and 1280x800 | see below |

## Browser walk result (320x640 and 1280x800)

Pages walked: `/login`, `/forgot-password`, `/verify-email/resend`, `/register/prefill/{blank}`, `/join/{invalid}`, then as Super Admin `/admin`, `/admin/users` (first 60 tab stops), `/admin/users/new`, `/admin/impersonations`, `/profile`, the impersonation confirmation, and with the impersonation banner active (a seeded player) `/family`, `/family/children`, `/family/me`, `/family/approvals`, `/profile`; Exit Impersonation was clicked at the end.

After the CSS fixes below, for all 32 page/viewport runs: no horizontal scrolling at 320 px (0 px overflow), the first Tab stop is "Skip to main content", every tab stop has a visible focus indicator, nothing is focused off-screen, and every tabbable control is at least 44 px high (radio and checkbox inputs are 24 px with a 44 px label).

Fixes made because of the first walk and the audit: header navigation, context selector, fieldset/legend, radio and checkbox labels had no styles (links were 17 px high) and are now flex-wrapped with 44 px targets; table row actions (Edit, Deactivate, Impersonate, Delete were 22 px high) and stand-alone page links now have 44 px targets; long words wrap inside `main`.

## Not covered (stated, not claimed)

- The walk did not sign in as a Trainer, Coach or a child login; those screens (and every other screen) are covered by the structural lint only, not by a real-browser keyboard walk or 320 px screenshot.
- No screen reader (NVDA, VoiceOver) was run; screen-reader support is asserted structurally only (labels, landmarks, `aria-describedby`, alert/status regions).
- Contrast of a Trainer's custom brand color on the actual pages was not sampled in a browser; it is guaranteed by the palette rule and its unit tests.
- The walk created a dev Super Admin (`a11y-admin@example.test`) and 10,000 sample users in the dev database; they are not in the test database.
