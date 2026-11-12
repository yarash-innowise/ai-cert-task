'use strict';
// Keyboard + 320 px walk of the running app (task 43, AC-89). Run with:
//   docker compose --profile a11y run --rm -e A11Y_ADMIN_EMAIL=... -e A11Y_ADMIN_PASSWORD=... a11y
// Dev data only; the Super Admin is created by the caller from the console. Prints a JSON report.
const { chromium } = require('playwright-core');

const BASE = 'https://localhost';
const email = process.env.A11Y_ADMIN_EMAIL;
const password = process.env.A11Y_ADMIN_PASSWORD;

async function walk(page, label, viewport) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  const stops = [];
  const limit = 60;
  for (let i = 0; i < limit; i++) {
    await page.keyboard.press('Tab');
    const stop = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) return null;
      const cs = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      const outline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0;
      const shadow = cs.boxShadow !== 'none';
      const name = (el.getAttribute('aria-label') || el.textContent || el.getAttribute('name') || el.id || '').trim().replace(/\s+/g, ' ').slice(0, 40);
      return {
        tag: el.tagName.toLowerCase() + (el.type ? ':' + el.type : ''),
        name,
        visibleFocus: outline || shadow,
        width: Math.round(r.width), height: Math.round(r.height),
        offscreenX: r.right > window.innerWidth + 1 || r.left < -1,
        key: (el.id || '') + '|' + el.tagName + '|' + (el.getAttribute('href') || el.getAttribute('name') || '') + '|' + name,
      };
    });
    if (stop === null) break;
    if (stops.length && stops.some((s) => s.key === stop.key)) break; // wrapped around
    stops.push(stop);
  }
  return {
    page: label, viewport: viewport.width + 'x' + viewport.height,
    horizontalOverflowPx: overflow,
    firstStop: stops[0] ? stops[0].name : null,
    tabStops: stops.length,
    withoutVisibleFocus: stops.filter((s) => !s.visibleFocus).map((s) => s.tag + ' ' + s.name),
    offscreen: stops.filter((s) => s.offscreenX).map((s) => s.tag + ' ' + s.name),
    smallerThan44: stops.filter((s) => s.height < 44 && !s.tag.startsWith('input:radio') && !s.tag.startsWith('input:checkbox')).map((s) => s.tag + ' ' + s.name + ' ' + s.width + 'x' + s.height),
  };
}

(async () => {
  const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP localhost app'] });
  const report = [];
  for (const viewport of [{ width: 320, height: 640 }, { width: 1280, height: 800 }]) {
    const context = await browser.newContext({ viewport, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const visit = async (path) => {
      await page.goto(BASE + path, { waitUntil: 'networkidle' });
      report.push(await walk(page, path, viewport));
    };
    for (const path of ['/login', '/forgot-password', '/verify-email/resend', '/register/prefill/' + 'A'.repeat(43), '/join/not-a-link']) await visit(path);

    await page.goto(BASE + '/login');
    await page.fill('#field-email', email);
    await page.fill('#field-password', password);
    await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
    for (const path of ['/admin', '/admin/users', '/admin/users/new', '/admin/impersonations', '/profile']) await visit(path);

    // Impersonate the first player in the list (keyboard-reachable form), then walk the player screens with the banner on
    await page.goto(BASE + '/admin/users?role=player&status=active');
    const href = await page.evaluate(() => { const a = [...document.querySelectorAll('a')].find((x) => /\/impersonate$/.test(x.getAttribute('href') || '')); return a ? a.getAttribute('href') : null; });
    if (href) {
      await page.goto(BASE + href);
      report.push(await walk(page, href + ' (confirm)', viewport));
      await Promise.all([page.waitForNavigation(), page.click('button[type=submit]:not(.button--secondary)')]);
      for (const path of ['/family', '/family/children', '/family/me', '/family/approvals', '/profile']) await visit(path);
      await Promise.all([page.waitForNavigation(), page.click('.impersonation-banner__exit')]);
    }
    await context.close();
  }
  await browser.close();
  console.log(JSON.stringify(report, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
