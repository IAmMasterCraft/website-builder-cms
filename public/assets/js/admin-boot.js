// Before first paint: apply the saved admin colour mode and sidebar state.
(() => {
  const root = document.documentElement;
  root.classList.add('js');
  try {
    const mode = localStorage.getItem('gather-admin-theme');
    if (mode === 'dark' || mode === 'light') root.dataset.theme = mode;
    if (localStorage.getItem('gather-admin-sidebar') === 'collapsed') root.classList.add('sidebar-collapsed');
  } catch { /* storage blocked: defaults apply */ }
})();
