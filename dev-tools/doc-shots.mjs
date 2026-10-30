// Dev only: screenshots for docs/ from a running demo site (light theme,
// 1280 px wide; a few at 375 px). Writes PNGs; convert them to WebP after:
//   node dev-tools/doc-shots.mjs http://127.0.0.1:8080 /tmp/shots admin@example.org:PASS amara.okafor@example.org:PASS [name-filter]
//   for f in /tmp/shots/*.png; do cwebp -quiet -q 78 -m 6 "$f" -o docs/images/$(basename "${f%.png}").webp; done
// Public pages are taken with reduced motion so scroll reveals do not leave
// parts faded out. Needs Google Chrome and playwright-core (suite node_modules).
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_CORE || path.resolve(here, '../../../node_modules/playwright-core'));
const chrome = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const [base, outDir, adminLogin, memberLogin, filter = ''] = process.argv.slice(2);
// filter: substring of shot names; several separated by commas.
const filters = filter ? filter.split(',') : [];
mkdirSync(outDir, { recursive: true });

// [file name, url, options]  who: admin|member|null; vp: d (1280) | m (375);
// h: capture height (page is cut there; default one screen); click/wait: selectors;
// follow: open the first link matching a selector; vh: window height; scrollTo: selector;
// hover: selector to point at before the shot; ctx: separate browser context (state such as the
// folded sidebar stays there); who 'imp': an admin viewing the site as the first member (B2).
const D = 'd', M = 'm';
const shots = [
  // Public site and members
  ['public-home', '/', { vp: D }],
  ['public-home-mobile', '/', { vp: M }],
  ['public-news', '/news', { vp: D, h: 1100 }],
  ['public-events', '/events', { vp: D, h: 1100 }],
  ['public-event', '/events/harbour-festival', { vp: D, h: 1100 }],
  ['public-calendar', '/events/calendar', { vp: D, h: 1100 }],
  ['public-gallery', '/gallery', { vp: D }],
  ['public-radio', '/radio', { vp: D }],
  ['public-join', '/join', { vp: D, h: 1300 }],
  ['public-join-mobile', '/join', { vp: M, h: 1200 }],
  ['public-login', '/login', { vp: D }],
  ['public-jobs', '/jobs', { vp: D, who: 'member' }],
  ['member-dashboard', '/account', { vp: D, who: 'member', h: 1200 }],
  ['member-dashboard-mobile', '/account', { vp: M, who: 'member', h: 1300 }],
  ['member-card', '/account/card', { vp: D, who: 'member', h: 1000 }],
  ['member-card-mobile', '/account/card', { vp: M, who: 'member', h: 1100 }],
  ['member-profile', '/account/profile', { vp: D, who: 'member', h: 1200 }],
  ['member-preferences', '/account/preferences', { vp: D, who: 'member' }],
  ['member-password', '/account/password', { vp: D, who: 'member', h: 1100 }],
  ['member-events', '/account/events', { vp: D, who: 'member' }],
  ['member-notices', '/account/notices', { vp: D, who: 'member' }],
  ['member-jobs-new', '/account/jobs/new', { vp: D, who: 'member', h: 1200 }],
  ['member-privacy', '/account/privacy', { vp: D, who: 'member' }],
  // Admin: site
  ['admin-overview', '/admin', { vp: D, who: 'admin', h: 1200 }],
  ['admin-overview-mobile', '/admin', { vp: M, who: 'admin' }],
  ['admin-pages', '/admin/pages', { vp: D, who: 'admin' }],
  ['admin-page-new', '/admin/pages/new', { vp: D, who: 'admin', h: 1100 }],
  ['admin-builder', '/admin/pages/5/builder', { vp: D, who: 'admin', wait: 2500 }],
  ['admin-builder-picker', '/admin/pages/5/builder', { vp: D, who: 'admin', wait: 2000, click: '[data-open-picker]' }],
  ['admin-builder-edit', '/admin/pages/5/builder', { vp: D, who: 'admin', wait: 2000, click: '.section-item:nth-child(1) [data-role="open"]' }],
  ['admin-page-settings', '/admin/pages/5/edit', { vp: D, who: 'admin', h: 1100 }],
  ['admin-templates', '/admin/templates', { vp: D, who: 'admin' }],
  ['admin-menus', '/admin/menus', { vp: D, who: 'admin' }],
  ['admin-menu', '/admin/menus/1', { vp: D, who: 'admin', h: 1100 }],
  ['admin-layout', '/admin/layout', { vp: D, who: 'admin', h: 1400 }],
  ['admin-media', '/admin/media', { vp: D, who: 'admin' }],
  ['admin-media-edit', '/admin/media/1', { vp: D, who: 'admin', h: 1100 }],
  // Admin: content
  ['admin-posts', '/admin/posts', { vp: D, who: 'admin' }],
  ['admin-post-edit', '/admin/posts/1/edit', { vp: D, who: 'admin', h: 1300 }],
  ['admin-categories', '/admin/categories', { vp: D, who: 'admin' }],
  ['admin-events', '/admin/events', { vp: D, who: 'admin' }],
  ['admin-event-edit', '/admin/events/1/edit', { vp: D, who: 'admin', h: 1600 }],
  ['admin-registrations', '/admin/events/1/registrations', { vp: D, who: 'admin' }],
  ['admin-albums', '/admin/albums', { vp: D, who: 'admin' }],
  ['admin-album-photos', '/admin/albums/1/photos', { vp: D, who: 'admin' }],
  ['admin-downloads', '/admin/downloads', { vp: D, who: 'admin' }],
  ['admin-leaders', '/admin/leaders', { vp: D, who: 'admin' }],
  ['admin-leader-edit', '/admin/leaders/1/edit', { vp: D, who: 'admin', h: 1300 }],
  ['admin-awards', '/admin/awards', { vp: D, who: 'admin' }],
  ['admin-faqs', '/admin/faqs', { vp: D, who: 'admin' }],
  ['admin-branches', '/admin/branches', { vp: D, who: 'admin' }],
  ['admin-groups', '/admin/groups', { vp: D, who: 'admin' }],
  // Admin: members
  ['admin-members', '/admin/members', { vp: D, who: 'admin' }],
  ['admin-member-edit', '/admin/members/1', { vp: D, who: 'admin', h: 1500 }],
  ['admin-members-import', '/admin/members/import', { vp: D, who: 'admin', h: 1100 }],
  ['admin-custom-fields', '/admin/custom-fields', { vp: D, who: 'admin' }],
  ['admin-custom-field-edit', '/admin/custom-fields/1', { vp: D, who: 'admin' }],
  ['admin-membership-settings', '/admin/settings/membership', { vp: D, who: 'admin', h: 1600 }],
  // Addendum C: location lists, site icon, the sign-up cascade
  ['admin-locations', '/admin/locations?country=NG', { vp: D, who: 'admin', h: 1100 }],
  ['admin-settings-icon', '/admin/settings', { vp: D, who: 'admin', vh: 900, scrollTo: '#site-icon' }],
  ['public-join-location', '/join', { vp: D, vh: 900, scrollTo: '[data-loc-prefix="res"]' }],
  ['admin-jobs', '/admin/jobs', { vp: D, who: 'admin', h: 1100 }],
  ['admin-comments', '/admin/comments', { vp: D, who: 'admin' }],
  // Admin: communications
  ['admin-campaigns', '/admin/campaigns', { vp: D, who: 'admin' }],
  ['admin-campaign-edit', '/admin/campaigns/1', { vp: D, who: 'admin', h: 1400, wait: 1500 }],
  ['admin-notices', '/admin/notices', { vp: D, who: 'admin' }],
  ['admin-notice-new', '/admin/notices/new', { vp: D, who: 'admin', h: 1100 }],
  ['admin-inbox', '/admin/inbox', { vp: D, who: 'admin' }],
  ['admin-inbox-message', '/admin/inbox', { vp: D, who: 'admin', h: 1000, follow: 'a[href^="/admin/inbox/"]' }],
  ['admin-email-templates', '/admin/email-templates', { vp: D, who: 'admin', h: 1100 }],
  ['admin-email-template-edit', '/admin/email-templates/welcome_member', { vp: D, who: 'admin', h: 1200 }],
  ['admin-email-log', '/admin/email-log', { vp: D, who: 'admin' }],
  ['admin-suppressions', '/admin/email-suppressions', { vp: D, who: 'admin' }],
  ['admin-email-settings', '/admin/settings/email', { vp: D, who: 'admin', h: 2600 }],
  // Admin: radio
  ['admin-radio', '/admin/radio', { vp: D, who: 'admin', h: 1200 }],
  ['admin-radio-media', '/admin/radio/media/1', { vp: D, who: 'admin', h: 1000 }],
  ['admin-radio-playlists', '/admin/radio/playlists', { vp: D, who: 'admin' }],
  ['admin-radio-playlist', '/admin/radio/playlists/2', { vp: D, who: 'admin', h: 1000 }],
  ['admin-radio-schedule', '/admin/radio/schedule', { vp: D, who: 'admin', h: 1200, wait: 1200 }],
  // Admin: system
  ['admin-design', '/admin/design', { vp: D, who: 'admin', h: 1400, wait: 1500 }],
  ['admin-settings', '/admin/settings', { vp: D, who: 'admin', h: 1500 }],
  ['admin-users', '/admin/users', { vp: D, who: 'admin' }],
  ['admin-profile', '/admin/profile', { vp: D, who: 'admin' }],
  ['admin-user-new', '/admin/users/new', { vp: D, who: 'admin', h: 1100 }],
  ['admin-audit', '/admin/audit', { vp: D, who: 'admin' }],
  ['admin-backups', '/admin/backups', { vp: D, who: 'admin', h: 1300 }],
  ['admin-health', '/admin/health', { vp: D, who: 'admin', h: 1500 }],
  ['admin-health-paths', '/admin/health', { vp: D, who: 'admin', vh: 1000, scrollTo: 'h2:text-is("Server and paths")' }],
  // Addendum B: sidebar groups, log in as a member, help centre
  ['admin-impersonate', '/admin/members', { vp: D, who: 'admin', follow: 'a[href^="/admin/impersonate/"]' }],
  ['admin-help', '/admin/help/manager-guide/members', { vp: D, who: 'admin' }],
  ['admin-sidebar-icons', '/admin/members', { vp: D, who: 'admin', ctx: 'icons', vh: 640, click: '[data-sidebar-toggle]', hover: '.nav-group[data-group="members"] .nav-group-toggle' }],
  ['member-impersonation', '/account', { vp: D, who: 'imp' }],
];

const browser = await chromium.launch({ executablePath: chrome, headless: true });
const contexts = {};
async function context(who, vp, extra = '') {
  const key = `${who}-${vp}-${extra}`;
  if (contexts[key]) return contexts[key];
  const viewport = vp === M ? { width: 375, height: 812 } : { width: 1280, height: 800 };
  // (per-shot window heights are set below with setViewportSize)
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, colorScheme: 'light', reducedMotion: who === 'admin' ? 'no-preference' : 'reduce', hasTouch: vp === M, isMobile: vp === M });
  await ctx.addInitScript(() => { try { localStorage.setItem('gather-admin-theme', 'light'); localStorage.removeItem('gather-admin-nav'); } catch { /* ignore */ } });
  const cred = who === 'admin' || who === 'imp' ? adminLogin : who === 'member' ? memberLogin : '';
  if (cred) {
    const [email, pass] = cred.split(':');
    const p = await ctx.newPage();
    await p.goto(base + '/login');
    await p.fill('input[name=email]', email);
    await p.fill('input[name=password]', pass);
    await Promise.all([p.waitForNavigation(), p.click('form[action="/login"] button[type=submit]')]);
    if (who === 'imp') {
      // Start viewing the site as the first member in the list (read only).
      await p.goto(base + '/admin/members');
      const href = await p.getAttribute('a[href^="/admin/impersonate/"]', 'href');
      await p.goto(base + href);
      await p.fill('#imp-reason', 'Member asked where to find her card');
      await Promise.all([p.waitForNavigation(), p.click('form[action^="/admin/impersonate/"] button[type=submit]')]);
    }
    await p.close();
  }
  contexts[key] = ctx;
  return ctx;
}

for (const [name, url, o] of shots) {
  if (filters.length && !filters.some((f) => name.includes(f))) continue;
  const ctx = await context(o.who || 'guest', o.vp || D, o.ctx || '');
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  if (o.vh) await page.setViewportSize({ width: o.vp === M ? 375 : 1280, height: o.vh });
  let res = await page.goto(base + url, { waitUntil: 'load' });
  if (o.follow) {
    // Open the first link matching the selector (ids differ between databases).
    const href = await page.getAttribute(o.follow, 'href').catch(() => null);
    if (href) res = await page.goto(base + href, { waitUntil: 'load' }); else errors.push('no link for ' + o.follow);
  }
  await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
  await page.waitForTimeout(o.wait || 700);
  // Keep the local account name out of published screenshots.
  if (process.env.USER) await page.evaluate((u) => { const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); while (w.nextNode()) { if (w.currentNode.nodeValue.includes(u)) w.currentNode.nodeValue = w.currentNode.nodeValue.split(u).join('gather'); } }, process.env.USER);
  if (o.click) {
    const el = await page.$(o.click);
    if (el) { await el.click(); await page.waitForTimeout(900); } else { errors.push('click target not found: ' + o.click); }
  }
  if (o.hover) {
    const el = await page.$(o.hover);
    if (el) { await el.hover(); await page.waitForTimeout(500); } else { errors.push('hover target not found: ' + o.hover); }
  }
  if (o.scrollTo) {
    const el = await page.$(o.scrollTo);
    if (el) { await el.evaluate((n) => { n.scrollIntoView({ block: 'start' }); window.scrollBy(0, -90); }); await page.waitForTimeout(400); }
  }
  const vw = o.vp === M ? 375 : 1280;
  const file = path.join(outDir, name + '.png');
  if (o.h && o.who === 'admin') {
    // A taller window keeps the fixed admin sidebar full height.
    const full = await page.evaluate(() => document.documentElement.scrollHeight);
    await page.setViewportSize({ width: vw, height: Math.min(o.h, full) });
    await page.waitForTimeout(400);
    await page.screenshot({ path: file });
  } else if (o.h) {
    const full = await page.evaluate(() => document.documentElement.scrollHeight);
    await page.screenshot({ path: file, fullPage: true, clip: { x: 0, y: 0, width: vw, height: Math.min(o.h, full) } });
  } else {
    await page.screenshot({ path: file });
  }
  console.log(`${res ? res.status() : '???'} ${name}${errors.length ? '  ! ' + errors.join(' | ') : ''}`);
  await page.close();
}
await browser.close();
