// Campaign editor and notice form enhancements. The forms work without
// this file (every button submits); this adds autosave, a live preview
// refresh and shows only the audience fields that apply.
const csrf = document.querySelector('meta[name="csrf"]')?.content ?? '';

// Audience picker: show the extra field for the chosen type.
document.querySelectorAll('[data-audience]').forEach((set) => {
  const select = set.querySelector('select[name="audience_type"]');
  const update = () => {
    set.querySelectorAll('[data-audience-for]').forEach((el) => { el.hidden = el.dataset.audienceFor !== select.value; });
  };
  select?.addEventListener('change', update);
  update();
});

// Preview width (desktop / mobile).
document.querySelectorAll('[data-preview-width]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const frame = document.querySelector('[data-preview-frame]');
    if (!frame) return;
    frame.style.width = btn.dataset.previewWidth;
    btn.parentElement.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
  });
});

const form = document.querySelector('[data-campaign-form]');
if (form) {
  const state = document.querySelector('[data-save-state]');
  const frame = document.querySelector('[data-preview-frame]');
  let dirty = false;
  let timer = null;
  let saving = null;

  const show = (text, kind = '') => {
    if (!state) return;
    state.textContent = text;
    state.dataset.kind = kind;
  };

  const save = async () => {
    clearTimeout(timer);
    if (saving) return saving;
    const body = new FormData(form);
    body.set('op', 'save');
    show('Saving…', 'saving');
    dirty = false;
    saving = (async () => {
      try {
        const res = await fetch(form.action, {
          method: 'POST',
          headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
          body,
          credentials: 'same-origin',
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.ok) throw new Error(data?.error ?? `Save failed (${res.status})`);
        const problems = Object.keys(data.errors ?? {}).length;
        show(problems ? `Saved at ${data.saved_at} · ${problems} field${problems === 1 ? '' : 's'} to fix` : `Saved at ${data.saved_at}`, problems ? 'error' : '');
        const count = document.querySelector('[data-reach-count]');
        if (count && typeof data.recipients === 'number') count.textContent = String(data.recipients);
        if (frame) frame.src = frame.src.split('#')[0];
      } catch (err) {
        dirty = true;
        show(err instanceof Error ? err.message : 'Save failed', 'error');
      } finally {
        saving = null;
      }
    })();
    return saving;
  };

  const changed = () => {
    dirty = true;
    show('Unsaved changes', 'dirty');
    clearTimeout(timer);
    timer = setTimeout(save, 1200);
  };
  form.addEventListener('input', changed);
  form.addEventListener('change', changed);

  // Send / schedule / test use the saved campaign: save pending edits first.
  document.querySelectorAll('form[data-save-first]').forEach((f) => {
    f.addEventListener('submit', async (e) => {
      if (e.defaultPrevented || !dirty) return;
      e.preventDefault();
      const button = f.querySelector('[type="submit"]');
      if (button) button.disabled = true;
      await save();
      f.submit();
    });
  });

  window.addEventListener('beforeunload', (e) => {
    if (dirty) e.preventDefault();
  });
  form.addEventListener('submit', () => { dirty = false; });
}
