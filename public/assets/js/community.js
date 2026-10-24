// Likes without a page reload, confirmations, and notices marked read when
// they have been on screen. All forms also work without this file.
const csrf = document.querySelector('meta[name="csrf"]')?.content ?? '';

async function postForm(form) {
  const res = await fetch(form.action, {
    method: 'POST',
    headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
    body: new FormData(form),
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data) throw new Error(data?.error ?? `Request failed (${res.status})`);
  return data;
}

export function initCommunity() {
  document.querySelectorAll('form[data-like]').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const button = form.querySelector('button');
      button.disabled = true;
      try {
        const data = await postForm(form);
        button.setAttribute('aria-pressed', String(data.liked));
        const count = form.querySelector('[data-like-count]');
        if (count) count.textContent = String(data.count);
        const label = form.querySelector('.like-label');
        if (label) label.textContent = data.liked ? 'Liked' : (label.dataset.off ?? 'Like');
      } catch {
        form.submit();
      } finally {
        button.disabled = false;
      }
    });
    const label = form.querySelector('.like-label');
    if (label && form.querySelector('button')?.getAttribute('aria-pressed') !== 'true') label.dataset.off = label.textContent;
  });

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!window.confirm(form.dataset.confirm ?? 'Are you sure?')) e.preventDefault();
    });
  });

  // Notices: mark read after being visible for a moment.
  const unread = document.querySelectorAll('[data-unread-notice]');
  if (unread.length && 'IntersectionObserver' in window) {
    const timers = new Map();
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        const el = entry.target;
        if (entry.isIntersecting) {
          timers.set(el, setTimeout(async () => {
            io.unobserve(el);
            const form = el.querySelector('.notice-read-form');
            if (!form) return;
            try {
              const data = await postForm(form);
              el.classList.remove('is-unread');
              el.querySelector('.unread-dot')?.remove();
              el.querySelector('.sr-only')?.remove();
              form.remove();
              const summary = document.querySelector('[data-unread-summary]');
              if (summary) summary.textContent = data.unread ? `${data.unread} unread notice${data.unread === 1 ? '' : 's'}` : 'You are all caught up.';
              document.querySelectorAll('.account-nav .count-pill').forEach((pill) => {
                if (data.unread) pill.firstChild.textContent = String(data.unread); else pill.remove();
              });
            } catch { /* the button still works */ }
          }, 1500));
        } else {
          clearTimeout(timers.get(el));
        }
      });
    }, { threshold: 0.6 });
    unread.forEach((el) => io.observe(el));
  }
}
