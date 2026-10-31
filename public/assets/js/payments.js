// Addendum D2: payments. Everything works without this file; JS adds
// comfort: the amount follows the chosen charge, "Other" shows its name
// field only when chosen, amounts are tidied on blur, a receipt preview,
// and keyboard shortcuts on the admin review screen.

function initPayForm(form) {
  const target = form.querySelector('[data-pay-target]');
  const other = form.querySelector('[data-pay-other]');
  const amount = form.querySelector('[data-pay-amount]');
  const hint = form.querySelector('[data-pay-amount-hint]');
  const defaultHint = hint ? hint.textContent : '';
  const decimals = Number(amount?.dataset.decimals ?? 2);

  const sync = (fromUser) => {
    const opt = target?.selectedOptions?.[0];
    if (other) other.hidden = target?.value !== 'other' && !other.querySelector('[aria-invalid="true"]');
    if (!opt || !amount) return;
    if (fromUser && opt.dataset.amount) amount.value = opt.dataset.amount;
    if (hint) {
      if (opt.dataset.balance) {
        hint.textContent = `Balance ${opt.dataset.balance}. ` + (opt.dataset.part === '0' ? 'This item must be paid in full.' : 'Part payments are fine.');
      } else {
        hint.textContent = defaultHint;
      }
    }
  };
  target?.addEventListener('change', () => sync(true));
  sync(false);

  // Tidy the typed amount ("30" -> "30.00", "1,250.5" -> "1,250.50"); the server parses it either way.
  amount?.addEventListener('blur', () => {
    const raw = amount.value.replace(/[^\d.,]/g, '');
    if (!/^(\d{1,3}(,\d{3})+|\d+)(\.\d*)?$/.test(raw)) return;
    const [whole, frac = ''] = raw.split('.');
    if (frac.length > decimals) return;
    amount.value = decimals > 0 ? `${whole}.${frac.padEnd(decimals, '0')}` : whole;
  });

  // Preview of the chosen receipt.
  const file = form.querySelector('[data-pay-receipt]');
  const preview = form.querySelector('[data-pay-preview]');
  let url = null;
  file?.addEventListener('change', () => {
    if (!preview) return;
    if (url) URL.revokeObjectURL(url);
    url = null;
    preview.replaceChildren();
    const f = file.files?.[0];
    if (!f) { preview.hidden = true; return; }
    const label = document.createElement('span');
    label.textContent = `${f.name} (${Math.max(1, Math.round(f.size / 1024))} KB)` + (f.size > 5 * 1024 * 1024 ? ': too large, the limit is 5 MB' : '');
    if (f.type.startsWith('image/')) {
      url = URL.createObjectURL(f);
      const img = document.createElement('img');
      img.src = url;
      img.alt = 'Preview of the receipt you chose';
      preview.append(img);
    }
    preview.append(label);
    preview.hidden = false;
  });
}

// Admin review screen: n / p move between pending reports, a approves, r and i focus the reason and question fields.
function initReview(root) {
  document.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    const t = e.target;
    if (t instanceof HTMLElement && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName))) return;
    const go = (sel) => { const a = root.querySelector(sel); if (a instanceof HTMLAnchorElement) { e.preventDefault(); window.location.href = a.href; } };
    const focus = (sel) => { const el = root.querySelector(sel); if (el instanceof HTMLElement) { e.preventDefault(); el.focus(); } };
    switch (e.key) {
      case 'n': go('[data-pay-next]'); break;
      case 'p': go('[data-pay-prev]'); break;
      case 'a': focus('[data-pay-approve]'); break;
      case 'r': focus('[data-pay-reason]'); break;
      case 'i': focus('[data-pay-question]'); break;
      default: break;
    }
  });
  root.querySelectorAll('[data-pay-preset]').forEach((sel) => {
    sel.addEventListener('change', () => {
      const field = root.querySelector('[data-pay-reason]');
      if (field && sel.value) field.value = sel.value;
    });
  });
}

export function initPayments() {
  document.querySelectorAll('[data-pay-form]').forEach(initPayForm);
  const review = document.querySelector('[data-pay-review]');
  if (review) initReview(review);
  document.querySelectorAll('[data-pay-preset]').forEach((sel) => {
    if (review?.contains(sel)) return;
    sel.addEventListener('change', () => {
      const field = sel.closest('form')?.querySelector('[data-pay-reason]');
      if (field && sel.value) field.value = sel.value;
    });
  });
}
