// Dev-only accessibility audit: runs axe-core in headless Chrome against a
// running Gather site. Usage:
//   node dev-tools/audit.mjs http://127.0.0.1:8080 admin@email adminpass member@email memberpass [outfile.json]
// Needs playwright-core (resolved from PLAYWRIGHT_CORE or the eng-suite node_modules) and Google Chrome.
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const pwPath = process.env.PLAYWRIGHT_CORE || path.resolve(here, '../../../node_modules/playwright-core');
const { chromium } = require(pwPath);
const axeSource = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const [base, adminEmail, adminPass, memberEmail, memberPass, out] = process.argv.slice(2);
const chrome = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const publicPages = process.env.PUBLIC_PAGES ? process.env.PUBLIC_PAGES.split(',') : ['/'];
const memberPages = ['/account', '/account/password', '/account/card'];
const adminPages = (process.env.ADMIN_PAGES || '/admin,/admin/users,/admin/users/new,/admin/audit,/admin/backups,/admin/health,/admin/members,/admin/settings,/admin/posts').split(',');

async function login(context, email, pass) {
  const page = await context.newPage();
  await page.goto(base + '/login');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', pass);
  await Promise.all([page.waitForNavigation(), page.click('form[action="/login"] button[type=submit]')]);
  await page.close();
}

async function audit(context, url, label) {
  const page = await context.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  const res = await page.goto(base + url, { waitUntil: 'networkidle' });
  await page.addScriptTag({ content: axeSource });
  const result = await page.evaluate(async () => {
    // eslint-disable-next-line no-undef
    const r = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] } });
    return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.slice(0, 5).map((n) => ({ target: n.target.join(' '), summary: n.failureSummary })) , count: v.nodes.length }));
  });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  await page.close();
  return { label, url, status: res?.status(), violations: result, consoleErrors: errors, overflow };
}

const browser = await chromium.launch({ executablePath: chrome, headless: true });
const results = [];
for (const [vpName, viewport] of [['desktop', { width: 1366, height: 900 }], ['mobile', { width: 375, height: 800 }]]) {
  const visitor = await browser.newContext({ viewport, bypassCSP: true });
  for (const u of publicPages) results.push({ vp: vpName, ...(await audit(visitor, u, 'visitor')) });
  const member = await browser.newContext({ viewport, bypassCSP: true });
  await login(member, memberEmail, memberPass);
  for (const u of memberPages) results.push({ vp: vpName, ...(await audit(member, u, 'member')) });
  const admin = await browser.newContext({ viewport, colorScheme: "light", bypassCSP: true });
  await login(admin, adminEmail, adminPass);
  for (const u of adminPages) results.push({ vp: vpName, ...(await audit(admin, u, 'admin')) });
  // Admin dark mode on desktop only.
  if (vpName === 'desktop') {
    const dark = await browser.newContext({ viewport, colorScheme: "dark", bypassCSP: true });
    await login(dark, adminEmail, adminPass);
    for (const u of adminPages.slice(0, 4)) results.push({ vp: 'desktop-dark', ...(await audit(dark, u, 'admin')) });
  }
}
await browser.close();

let serious = 0;
for (const r of results) {
  const bad = r.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  serious += bad.length;
  const minor = r.violations.filter((v) => !(v.impact === 'serious' || v.impact === 'critical'));
  console.log(`${r.vp.padEnd(12)} ${String(r.status).padEnd(4)} ${r.url.padEnd(48)} serious/critical: ${bad.length}  moderate/minor: ${minor.length}${r.overflow ? '  HORIZONTAL OVERFLOW' : ''}${r.consoleErrors.length ? '  console errors: ' + r.consoleErrors.length : ''}`);
  for (const v of r.violations) {
    console.log(`    [${v.impact}] ${v.id}: ${v.help} (${v.count})`);
    for (const n of v.nodes.slice(0, 3)) console.log(`        ${n.target}`);
  }
  for (const e of r.consoleErrors.slice(0, 3)) console.log(`    console: ${e.slice(0, 200)}`);
}
if (out) writeFileSync(out, JSON.stringify(results, null, 2));
console.log(`\n${results.length} page runs, ${serious} serious/critical violation types`);
process.exit(serious ? 1 : 0);
