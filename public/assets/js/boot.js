// Runs before first paint: flags that JavaScript is available so CSS can
// collapse menus that JS will control. Everything else lives in modules.
document.documentElement.classList.replace('no-js', 'js');
