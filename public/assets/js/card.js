// Member card: QR codes (vendored qrcode-generator, MIT) and a PNG download
// drawn on a canvas from the same data as the HTML card (§4.3).
import qrcode from '../vendor/qrcode-generator/qrcode.js';

const SVG = 'http://www.w3.org/2000/svg';

/** Square module matrix for a URL. */
export function qrMatrix(text) {
  const qr = qrcode(0, 'M');
  qr.addData(text);
  qr.make();
  const n = qr.getModuleCount();
  return Array.from({ length: n }, (_, r) => Array.from({ length: n }, (_, c) => qr.isDark(r, c)));
}

function qrSvg(text) {
  const m = qrMatrix(text);
  const n = m.length;
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('viewBox', `0 0 ${n} ${n}`);
  svg.setAttribute('shape-rendering', 'crispEdges');
  svg.setAttribute('aria-hidden', 'true');
  let d = '';
  m.forEach((row, r) => row.forEach((dark, c) => { if (dark) d += `M${c} ${r}h1v1h-1z`; }));
  const path = document.createElementNS(SVG, 'path');
  path.setAttribute('d', d);
  path.setAttribute('fill', '#000');
  svg.append(path);
  return svg;
}

document.querySelectorAll('[data-qr]').forEach((box) => {
  try { box.replaceChildren(qrSvg(box.dataset.qr ?? '')); } catch { /* leave the fallback text */ }
});

// ---- PNG -----------------------------------------------------------------------
const css = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

function loadImage(src) {
  return new Promise((resolve) => {
    if (!src) { resolve(null); return; }
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => resolve(null);
    img.src = src;
  });
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

function fit(ctx, text, maxWidth) {
  if (ctx.measureText(text).width <= maxWidth) return text;
  let t = text;
  while (t.length > 1 && ctx.measureText(`${t}…`).width > maxWidth) t = t.slice(0, -1);
  return `${t}…`;
}

function cover(ctx, img, x, y, w, h) {
  const s = Math.max(w / img.naturalWidth, h / img.naturalHeight);
  const sw = w / s;
  const sh = h / s;
  ctx.drawImage(img, (img.naturalWidth - sw) / 2, (img.naturalHeight - sh) / 2, sw, sh, x, y, w, h);
}

/** Draw both sides (front above back) and return a canvas. W = card width in px. */
export async function drawCard(card, W = 1012) {
  const H = Math.round(W * 53.98 / 85.6);
  const u = W / 100; // 1cqw
  const gap = Math.round(6 * u);
  const canvas = document.createElement('canvas');
  canvas.width = W;
  canvas.height = H * 2 + gap;
  const ctx = canvas.getContext('2d');
  const c = {
    brand: css('--c-brand', '#12355b'), brandInk: css('--c-brand-ink', '#ffffff'), brandSoft: css('--c-brand-soft', '#e6eef7'),
    accent: css('--c-accent', '#0b7577'), accentInk: css('--c-accent-ink', '#ffffff'), hi: css('--c-highlight', '#f2b246'), hiInk: css('--c-highlight-ink', '#0d2742'),
    ink: css('--c-ink', '#14202e'), soft: css('--c-ink-soft', '#2f3b4c'), muted: css('--c-muted', '#5a6779'), line: css('--c-line', '#d6dee8'), surface: css('--c-surface', '#ffffff'),
  };
  const fh = css('--font-heading', 'system-ui, sans-serif');
  const fb = css('--font-body', 'system-ui, sans-serif');
  await document.fonts?.ready;
  const [logo, photo, bg, sig] = await Promise.all([loadImage(card.logo), loadImage(card.photo), loadImage(card.background), loadImage(card.signature)]);

  // Front
  ctx.save();
  roundRect(ctx, 0, 0, W, H, 4.2 * u);
  ctx.clip();
  const grad = ctx.createLinearGradient(0, 0, W, H);
  grad.addColorStop(0.55, c.surface);
  grad.addColorStop(1, c.brandSoft);
  ctx.fillStyle = grad;
  ctx.fillRect(0, 0, W, H);
  if (bg) { ctx.globalAlpha = 0.22; cover(ctx, bg, 0, 0, W, H); ctx.globalAlpha = 1; }
  const topH = 13.8 * u;
  ctx.fillStyle = c.brand;
  ctx.fillRect(0, 0, W, topH);
  let x = 4.6 * u;
  if (logo) {
    const lh = 7 * u;
    const lw = Math.min(lh * (logo.naturalWidth / logo.naturalHeight), 20 * u);
    ctx.fillStyle = '#fff';
    roundRect(ctx, x, (topH - lh) / 2, lw, lh, u);
    ctx.fill();
    ctx.drawImage(logo, x + 0.4 * u, (topH - lh) / 2 + 0.4 * u, lw - 0.8 * u, lh - 0.8 * u);
    x += lw + 2.6 * u;
  } else {
    ctx.fillStyle = c.accent;
    roundRect(ctx, x, (topH - 7 * u) / 2, 7 * u, 7 * u, 1.6 * u);
    ctx.fill();
    ctx.fillStyle = c.accentInk;
    ctx.font = `800 ${4 * u}px ${fh}`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(card.initial, x + 3.5 * u, topH / 2);
    x += 9.6 * u;
  }
  ctx.textAlign = 'left';
  ctx.textBaseline = 'middle';
  ctx.font = `600 ${2.4 * u}px ${fb}`;
  const kind = 'MEMBER';
  const kw = ctx.measureText(kind).width + 4 * u;
  ctx.fillStyle = card.expired ? '#b42318' : c.hi;
  roundRect(ctx, W - 4.6 * u - kw, topH / 2 - 2 * u, kw, 4 * u, 2 * u);
  ctx.fill();
  ctx.fillStyle = card.expired ? '#fff' : c.hiInk;
  ctx.fillText(kind, W - 4.6 * u - kw + 2 * u, topH / 2 + 0.1 * u);
  ctx.fillStyle = c.brandInk;
  ctx.font = `700 ${3.9 * u}px ${fh}`;
  ctx.fillText(fit(ctx, card.org, W - x - kw - 8 * u), x, topH / 2);
  // Photo
  const px = 4.6 * u;
  const ps = 27 * u;
  const py = topH + (H - topH - ps) / 2;
  ctx.fillStyle = '#fff';
  roundRect(ctx, px - 0.7 * u, py - 0.7 * u, ps + 1.4 * u, ps + 1.4 * u, 3.4 * u);
  ctx.fill();
  ctx.save();
  roundRect(ctx, px, py, ps, ps, 3 * u);
  ctx.clip();
  if (photo) cover(ctx, photo, px, py, ps, ps); else { ctx.fillStyle = c.line; ctx.fillRect(px, py, ps, ps); }
  ctx.restore();
  // Details
  const ix = px + ps + 4.4 * u;
  const iw = W - ix - 4.6 * u;
  let y = topH + 7.6 * u;
  ctx.textBaseline = 'alphabetic';
  ctx.fillStyle = c.ink;
  ctx.font = `750 ${5.6 * u}px ${fh}`;
  ctx.fillText(fit(ctx, card.name, iw), ix, y);
  y += 5.4 * u;
  const rows = [['MEMBER NO.', card.number || 'Pending', true], ['TYPE', card.type], ['VALID UNTIL', card.valid_until + (card.expired ? ' (expired)' : '')]]
    .concat((card.extra ?? []).slice(0, 2).map((e) => [e.label.toUpperCase(), e.value]));
  rows.forEach(([label, value, strong]) => {
    ctx.fillStyle = c.muted;
    ctx.font = `600 ${2.3 * u}px ${fb}`;
    ctx.fillText(fit(ctx, label, 18 * u), ix, y);
    ctx.fillStyle = strong ? c.brand : c.ink;
    ctx.font = `${strong ? 750 : 600} ${3.2 * u}px ${fb}`;
    ctx.fillText(fit(ctx, value, iw - 20.5 * u), ix + 20.5 * u, y);
    y += 4.4 * u;
  });
  ctx.restore();

  // Back
  const oy = H + gap;
  ctx.save();
  ctx.translate(0, oy);
  roundRect(ctx, 0, 0, W, H, 4.2 * u);
  ctx.clip();
  ctx.fillStyle = c.surface;
  ctx.fillRect(0, 0, W, H);
  const pad = 4.6 * u;
  ctx.fillStyle = c.brand;
  ctx.font = `700 ${4.4 * u}px ${fh}`;
  ctx.fillText(fit(ctx, card.org, W - 40 * u), pad, pad + 4 * u);
  ctx.fillStyle = c.soft;
  ctx.font = `400 ${2.8 * u}px ${fb}`;
  let by = pad + 9.6 * u;
  (card.contact ?? []).forEach((line) => { ctx.fillText(fit(ctx, line, W - 42 * u), pad, by); by += 3.8 * u; });
  ctx.fillStyle = c.muted;
  ctx.font = `400 ${2.2 * u}px ${fb}`;
  if (card.charity) { ctx.fillText(fit(ctx, `Registered charity ${card.charity}`, W - 42 * u), pad, by + u); by += 3.2 * u; }
  ctx.fillText(fit(ctx, `If found, please return this card to ${card.org}.`, W - 42 * u), pad, by + u);
  if (card.verify_url) {
    const m = qrMatrix(card.verify_url);
    const qs = 26 * u;
    const qx = W - pad - qs;
    const qy = pad;
    ctx.fillStyle = '#fff';
    ctx.fillRect(qx, qy, qs, qs);
    const cell = (qs - 2.4 * u) / m.length;
    ctx.fillStyle = '#000';
    m.forEach((row, r) => row.forEach((dark, col) => { if (dark) ctx.fillRect(qx + 1.2 * u + col * cell, qy + 1.2 * u + r * cell, Math.ceil(cell), Math.ceil(cell)); }));
    ctx.fillStyle = c.muted;
    ctx.textAlign = 'center';
    ctx.fillText('Scan to check', qx + qs / 2, qy + qs + 3 * u);
    ctx.textAlign = 'left';
  }
  const sy = H - pad - 2 * u;
  ctx.strokeStyle = c.line;
  ctx.lineWidth = 0.3 * u;
  ctx.beginPath();
  ctx.moveTo(pad, sy - 8 * u);
  ctx.lineTo(W - pad, sy - 8 * u);
  ctx.stroke();
  if (sig) {
    const sh = 7 * u;
    const sw = Math.min(sh * (sig.naturalWidth / sig.naturalHeight), 30 * u);
    ctx.drawImage(sig, pad, sy - sh, sw, sh);
  } else {
    ctx.strokeStyle = c.soft;
    ctx.beginPath();
    ctx.moveTo(pad, sy);
    ctx.lineTo(pad + 26 * u, sy);
    ctx.stroke();
  }
  ctx.fillStyle = c.muted;
  ctx.font = `400 ${2.4 * u}px ${fb}`;
  ctx.fillText(`Authorised signature · ${card.signatory}`, pad + 33 * u, sy);
  ctx.restore();
  return canvas;
}

document.querySelectorAll('[data-card-png]').forEach((btn) => {
  btn.hidden = false;
  btn.addEventListener('click', async () => {
    let card;
    try { card = JSON.parse(btn.dataset.cardPng ?? '{}'); } catch { return; }
    btn.disabled = true;
    try {
      const canvas = await drawCard(card);
      canvas.toBlob((blob) => {
        if (!blob) return;
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `member-card-${(card.number || 'pending').replace(/[^A-Za-z0-9-]/g, '')}.png`;
        document.body.append(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
      }, 'image/png');
    } finally {
      btn.disabled = false;
    }
  });
});

document.querySelectorAll('[data-print]').forEach((btn) => {
  btn.hidden = false;
  btn.addEventListener('click', () => window.print());
});
