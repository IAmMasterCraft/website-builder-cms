// Before first paint: apply the saved admin colour mode, sidebar state and
// the sidebar groups the user opened (B1; read by CSS through data-nav-open).
(() => {
  const root = document.documentElement;
  root.classList.add('js');
  try {
    const mode = localStorage.getItem('gather-admin-theme');
    if (mode === 'dark' || mode === 'light') root.dataset.theme = mode;
    if (localStorage.getItem('gather-admin-sidebar') === 'collapsed') root.classList.add('sidebar-collapsed');
    const nav = JSON.parse(localStorage.getItem('gather-admin-nav') || '{}');
    if (nav && typeof nav === 'object' && !Array.isArray(nav)) {
      root.dataset.navOpen = Object.keys(nav).filter((k) => nav[k] === true && /^[a-z]+$/.test(k)).join(' ');
    }
  } catch { /* storage blocked or bad JSON: defaults apply */ }
})();
