// Design screen: live preview and contrast checks. Mirrors design_tokens()
// and design_contrast_report() in src/lib/design.php (tests pin the PHP side).
const form = document.querySelector('[data-design]');
const configEl = document.getElementById('design-config');

export const hexOk = (h) => /^#[0-9a-f]{6}$/i.test(h);
const toRgb = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
const toHex = (rgb) => '#' + rgb.map((c) => Math.max(0, Math.min(255, Math.round(c))).toString(16).padStart(2, '0')).join('');
export const mix = (a, b, t) => { const x = toRgb(a); const y = toRgb(b); return toHex(x.map((c, i) => c + (y[i] - c) * t)); };
export function luminance(h) {
  return toRgb(h).reduce((sum, c, i) => {
    const s = c / 255;
    const lin = s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    return sum + [0.2126, 0.7152, 0.0722][i] * lin;
  }, 0);
}
export const contrast = (a, b) => { const la = luminance(a); const lb = luminance(b); return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05); };
export const bestInk = (bg) => (contrast(bg, '#ffffff') >= contrast(bg, '#111111') ? '#ffffff' : '#111111');
export function ensureContrast(fg, bg, target = 4.5) {
  let c = fg;
  for (let i = 0; i < 20 && contrast(c, bg) < target; i++) c = mix(c, '#000000', 0.1);
  return c;
}

export function tokens(d, fonts, radii) {
  const brandStrong = mix(d.brand, '#000000', 0.28);
  const accentSoft = mix(d.accent, '#ffffff', 0.86);
  const darkInk = bestInk(brandStrong);
  const [s, m, l, xl] = radii[d.radius] ?? [6, 10, 16, 24];
  return {
    '--c-brand': d.brand,
    '--c-brand-strong': brandStrong,
    '--c-brand-ink': bestInk(d.brand),
    '--c-brand-soft': mix(d.brand, '#ffffff', 0.9),
    '--c-on-dark-strong': darkInk,
    '--c-on-dark': darkInk === '#ffffff' ? '#e9eef4' : '#1b1b1b',
    '--c-on-dark-muted': darkInk === '#ffffff' ? mix('#e9eef4', brandStrong, 0.22) : mix('#1b1b1b', brandStrong, 0.25),
    '--c-accent': d.accent,
    '--c-accent-strong': mix(d.accent, '#000000', 0.18),
    '--c-accent-ink': bestInk(d.accent),
    '--c-accent-soft': accentSoft,
    '--c-accent-text': ensureContrast(d.accent, accentSoft),
    '--c-link': ensureContrast(d.accent, d.surface ?? '#ffffff'),
    '--focus-ring': `color-mix(in srgb, ${d.accent} 30%, transparent)`,
    '--font-heading': fonts[d.heading_font] ?? '',
    '--font-body': fonts[d.body_font] ?? '',
    '--radius-s': `${s}px`, '--radius-m': `${m}px`, '--radius-l': `${l}px`, '--radius-xl': `${xl}px`,
    '--button-radius': d.button === 'square' ? `${s}px` : d.button === 'rounded' ? `${m}px` : '999px',
  };
}

if (form && configEl) {
  let config;
  try { config = JSON.parse(configEl.textContent ?? '{}'); } catch { config = null; }
  if (config) init(config);
}

function init(config) {
  const frame = form.querySelector('[data-preview-frame]');
  const field = (name) => form.elements.namedItem(name);
  const radioValue = (name) => form.querySelector(`input[name="${name}"]:checked`)?.value ?? '';

  // Colour pickers sync with the hex text fields (the text field is what is submitted).
  form.querySelectorAll('input[type="color"][data-color-for]').forEach((picker) => {
    const text = document.getElementById(picker.dataset.colorFor);
    if (!text) return;
    picker.hidden = false;
    picker.addEventListener('input', () => { text.value = picker.value; update(); });
    text.addEventListener('input', () => { if (hexOk(text.value.trim())) { picker.value = text.value.trim().toLowerCase(); update(); } });
  });

  const current = () => {
    const theme = radioValue('theme');
    return {
      theme,
      brand: field('brand').value.trim().toLowerCase(),
      accent: field('accent').value.trim().toLowerCase(),
      heading_font: field('heading_font').value,
      body_font: field('body_font').value,
      radius: radioValue('radius'),
      button: radioValue('button'),
      surface: config.defaults[theme]?.surface ?? '#ffffff',
    };
  };

  function applyToFrame() {
    const d = current();
    if (!hexOk(d.brand) || !hexOk(d.accent)) return;
    const doc = frame?.contentDocument;
    if (!doc?.documentElement) return;
    const t = tokens(d, config.fonts, config.radii);
    Object.entries(t).forEach(([prop, value]) => doc.documentElement.style.setProperty(prop, value));
  }

  function updateContrast() {
    const d = current();
    if (!hexOk(d.brand) || !hexOk(d.accent)) return;
    const t = tokens(d, config.fonts, config.radii);
    const pairs = [
      [t['--c-brand-ink'], d.brand], [t['--c-accent-ink'], d.accent], [d.accent, d.surface],
      [t['--c-on-dark'], t['--c-brand-strong']], [t['--c-on-dark-muted'], t['--c-brand-strong']],
    ];
    pairs.forEach(([fg, bg], i) => {
      const li = form.querySelector(`[data-check="${i}"]`);
      if (!li) return;
      const ratio = contrast(fg, bg);
      const ok = Math.round(ratio * 100) / 100 >= 4.5;
      li.className = ok ? 'pass' : 'fail';
      li.querySelector('.contrast-ratio').textContent = `${ratio.toFixed(2)}:1`;
      const badge = li.querySelector('.badge');
      badge.textContent = ok ? 'Pass' : 'Too low';
      badge.className = `badge ${ok ? 'ok' : 'bad'}`;
      const svg = li.querySelector('svg');
      svg?.querySelector('rect')?.setAttribute('fill', bg);
      svg?.querySelector('text')?.setAttribute('fill', fg);
    });
  }

  function update() { applyToFrame(); updateContrast(); }

  // Switching theme loads that theme's stylesheet and resets the controls to its defaults.
  form.querySelectorAll('input[name="theme"]').forEach((radio) => {
    radio.addEventListener('change', () => {
      const d = config.defaults[radio.value];
      if (!d) return;
      ['brand', 'accent'].forEach((k) => {
        field(k).value = d[k];
        const picker = form.querySelector(`[data-color-for="d-${k}"]`);
        if (picker) picker.value = d[k];
      });
      field('heading_font').value = d.heading_font;
      field('body_font').value = d.body_font;
      form.querySelector(`input[name="radius"][value="${d.radius}"]`)?.click();
      form.querySelector(`input[name="button"][value="${d.button}"]`)?.click();
      if (frame) frame.src = `/?theme_preview=${encodeURIComponent(radio.value)}`;
      updateContrast();
    });
  });
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  frame?.addEventListener('load', () => {
    applyToFrame();
    // Keep visitors' links from navigating the preview away.
    frame.contentDocument?.addEventListener('click', (e) => {
      if (e.target instanceof Element && e.target.closest('a, button, form')) e.preventDefault();
    }, true);
  });

  form.querySelectorAll('[data-width]').forEach((btn) => {
    btn.addEventListener('click', () => {
      form.querySelectorAll('[data-width]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
      frame.parentElement.style.setProperty('--frame-width', btn.dataset.width);
    });
  });
  form.querySelectorAll('[data-confirm-click]').forEach((btn) => {
    btn.addEventListener('click', (e) => { if (!window.confirm(btn.dataset.confirmClick)) e.preventDefault(); });
  });
  update();
}
