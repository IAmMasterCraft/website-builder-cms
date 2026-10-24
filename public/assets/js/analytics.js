// Analytics from the admin's allow-listed provider (§7, §11). Cookieless
// providers load at once; providers that set cookies wait for consent in the
// cookie banner (choice kept for 180 days in a first-party cookie). Without
// JavaScript nothing loads and no banner is shown.
const meta = document.querySelector('meta[name="analytics"]');
let cfg = null;
try { cfg = meta ? JSON.parse(meta.content) : null; } catch { cfg = null; }

const CONSENT = 'gather_consent';
const readConsent = () => document.cookie.split('; ').find((c) => c.startsWith(CONSENT + '='))?.split('=')[1] ?? '';
const writeConsent = (v) => {
  const secure = location.protocol === 'https:' ? '; Secure' : '';
  document.cookie = `${CONSENT}=${v}; Max-Age=${v ? 15552000 : 0}; Path=/; SameSite=Lax${secure}`;
};

function load() {
  if (!cfg || document.querySelector('script[data-analytics]')) return;
  const s = document.createElement('script');
  s.defer = true;
  s.dataset.analytics = cfg.provider;
  if (cfg.provider === 'plausible') {
    s.dataset.domain = cfg.id;
    s.src = cfg.script;
  } else if (cfg.provider === 'fathom') {
    s.dataset.site = cfg.id;
    s.src = cfg.script;
  } else if (cfg.provider === 'ga4') {
    window.dataLayer = window.dataLayer || [];
    window.gtag = function gtag() { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', cfg.id, { anonymize_ip: true });
    s.src = `${cfg.script}?id=${encodeURIComponent(cfg.id)}`;
  } else {
    return;
  }
  document.head.append(s);
}

if (cfg && !cfg.consent) {
  load();
} else if (cfg) {
  const banner = document.querySelector('[data-cookie-banner]');
  const settingsBtn = document.querySelector('[data-cookie-settings]');
  const choice = readConsent();
  if (choice === 'yes') load();
  if (banner && choice === '') banner.hidden = false;
  if (settingsBtn) settingsBtn.hidden = false;
  banner?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-consent]');
    if (!btn) return;
    writeConsent(btn.dataset.consent);
    banner.hidden = true;
    if (btn.dataset.consent === 'yes') load();
    settingsBtn?.focus();
  });
  settingsBtn?.addEventListener('click', () => {
    // Withdrawing consent: forget the choice and ask again (cookies already set by the provider expire on their own).
    writeConsent('');
    if (banner) {
      banner.hidden = false;
      banner.querySelector('[data-consent="yes"]')?.focus();
    }
  });
}
