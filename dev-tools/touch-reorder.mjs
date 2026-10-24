// Dev-only check: drag the first builder section below the third with touch events.
//   node dev-tools/touch-reorder.mjs http://127.0.0.1:8080 admin@email password <pageId>
import { createRequire } from 'node:module';
const require = createRequire(process.env.PLAYWRIGHT_CORE_DIR || new URL('../../../node_modules/', import.meta.url).pathname);
const { chromium } = require('playwright-core');
const [base, email, pass, pageId = '1'] = process.argv.slice(2);
const b = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
const c = await b.newContext({ viewport: { width: 1100, height: 900 }, hasTouch: true, isMobile: false });
const p = await c.newPage();
await p.goto(base + '/login'); await p.fill('input[name=email]', email); await p.fill('input[name=password]', pass);
await Promise.all([p.waitForNavigation(), p.click('form[action="/login"] button[type=submit]')]);
await p.goto(base + `/admin/pages/${pageId}/builder`, { waitUntil: 'networkidle' });
const order = () => p.$$eval('.section-item', (els) => els.map((e) => e.querySelector('strong')?.textContent));
const before = await order();
const h0 = await (await p.$$('.section-item [data-role=handle]'))[0].boundingBox();
const t2 = await (await p.$$('.section-item'))[2].boundingBox();
const cdp = await c.newCDPSession(p);
const pt = (x, y) => [{ x, y, id: 1 }];
await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: pt(h0.x + h0.width / 2, h0.y + h0.height / 2) });
for (let i = 1; i <= 10; i++) {
  const y = h0.y + h0.height / 2 + (t2.y + t2.height * 0.8 - (h0.y + h0.height / 2)) * i / 10;
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: pt(h0.x + h0.width / 2, y) });
  await p.waitForTimeout(30);
}
await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
await p.waitForTimeout(1500);
const after = await order();
console.log('before', before.slice(0, 4));
console.log('after ', after.slice(0, 4));
console.log('live region:', await p.$eval('[aria-live]', (e) => e.textContent).catch(() => '?'));
console.log('save state:', await p.$eval('[data-save-state]', (e) => e.textContent).catch(() => '?'));
await b.close();
