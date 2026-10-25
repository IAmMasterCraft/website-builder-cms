// Dev-only visual check: screenshots of pages at desktop and phone widths,
// one image per screenful (after scrolling, so scroll reveals have run) plus
// an optional full-page image. Reports horizontal overflow per page.
// Usage:
//   node dev-tools/shots.mjs http://127.0.0.1:8080 outdir "home=/,news=/news" [--login email:pass] [--full] [--vp desktop,mobile] [--max 8] [--click selector] [--reduced] [--nojs]
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_CORE || path.resolve(here, '../../../node_modules/playwright-core'));
const chrome = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const [base, outDir, list, ...rest] = process.argv.slice(2);
const flag = (name) => rest.includes(name);
const opt = (name, dflt) => { const i = rest.indexOf(name); return i >= 0 ? rest[i + 1] : dflt; };
const pages = list.split(',').map((p) => { const i = p.indexOf('='); return { name: p.slice(0, i), url: p.slice(i + 1) }; });
const vps = { desktop: { width: 1440, height: 900 }, mobile: { width: 375, height: 812 } };
const wanted = opt('--vp', 'desktop,mobile').split(',');
const max = Number(opt('--max', '8'));
const login = opt('--login', '');
const reduced = flag('--reduced');
const click = opt('--click', '');
const nojs = flag('--nojs');
mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ executablePath: chrome, headless: true });
for (const vpName of wanted) {
  const ctx = await browser.newContext({ viewport: vps[vpName], deviceScaleFactor: 1, reducedMotion: reduced ? 'reduce' : 'no-preference', hasTouch: vpName === 'mobile', isMobile: vpName === 'mobile', javaScriptEnabled: !nojs });
  if (login) {
    const [email, pass] = login.split(':');
    const p = await ctx.newPage();
    await p.goto(base + '/login');
    await p.fill('input[name=email]', email);
    await p.fill('input[name=password]', pass);
    await Promise.all([p.waitForNavigation(), p.click('form[action="/login"] button[type=submit]')]);
    await p.close();
  }
  for (const { name, url } of pages) {
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    await page.goto(base + url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    if (click) { await page.click(click); await page.waitForTimeout(700); }
    const h = await page.evaluate(() => document.documentElement.scrollHeight);
    const vh = vps[vpName].height;
    const n = Math.min(max, Math.ceil(h / vh));
    for (let i = 0; i < n; i++) {
      await page.evaluate((y) => window.scrollTo(0, y), i * vh);
      await page.waitForTimeout(1100);
      await page.screenshot({ path: path.join(outDir, `${name}-${vpName}-${String(i).padStart(2, '0')}.png`) });
    }
    if (flag('--full')) {
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.screenshot({ path: path.join(outDir, `${name}-${vpName}-full.png`), fullPage: true });
    }
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    console.log(`${vpName} ${name} ${url} height=${h} overflow=${overflow}${errors.length ? ' errors=' + JSON.stringify(errors) : ''}`);
    await page.close();
  }
  await ctx.close();
}
await browser.close();
