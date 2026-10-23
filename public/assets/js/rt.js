// Rich text: a small toolbar over a contenteditable area synced to a textarea.
// The server sanitises whatever is submitted (Core\Sanitizer); safeNodes()
// only keeps the editing surface inert.
const RT_BUTTONS = [
  ['B', 'bold', 'Bold'], ['I', 'italic', 'Italic'], ['H2', 'h2', 'Heading'], ['H3', 'h3', 'Subheading'],
  ['• List', 'insertUnorderedList', 'Bulleted list'], ['1. List', 'insertOrderedList', 'Numbered list'],
  ['“ ”', 'blockquote', 'Quote'], ['Link', 'link', 'Add link'], ['Unlink', 'unlink', 'Remove link'], ['HTML', 'source', 'Edit HTML'],
];

/** Parse HTML without running anything and drop active content. */
export function safeNodes(html) {
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
  doc.querySelectorAll('script,style,iframe,object,embed,form,input,button,textarea,select,link,meta,svg,math').forEach((el) => el.remove());
  doc.body.querySelectorAll('*').forEach((el) => {
    [...el.attributes].forEach((attr) => {
      const name = attr.name.toLowerCase();
      const value = attr.value.trim().toLowerCase();
      if (name.startsWith('on') || name === 'style' || ((name === 'href' || name === 'src') && /^(javascript|data|vbscript):/.test(value))) {
        el.removeAttribute(attr.name);
      }
    });
  });
  return [...doc.body.childNodes].map((n) => document.importNode(n, true));
}

/**
 * Enhance a [data-rt] wrapper holding .rt-editor + textarea[data-richtext].
 * Changes are copied to the textarea, which then fires an "input" event.
 */
export function initRichText(wrap) {
  const editor = wrap.querySelector('.rt-editor');
  const textarea = wrap.querySelector('textarea[data-richtext]');
  if (!editor || !textarea || wrap.classList.contains('rt-ready')) return;
  editor.hidden = false;
  wrap.classList.add('rt-ready');
  let sourceMode = false;
  const toolbar = document.createElement('div');
  toolbar.className = 'rt-toolbar';
  toolbar.setAttribute('role', 'toolbar');
  toolbar.setAttribute('aria-label', 'Formatting');
  const sync = () => {
    if (sourceMode) return;
    textarea.value = editor.innerHTML;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  };
  RT_BUTTONS.forEach(([text, cmd, label]) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = text;
    b.title = label;
    b.setAttribute('aria-label', label);
    if (cmd === 'source') b.setAttribute('aria-pressed', 'false');
    b.addEventListener('click', () => {
      if (cmd === 'source') {
        sourceMode = !sourceMode;
        b.setAttribute('aria-pressed', String(sourceMode));
        if (sourceMode) {
          textarea.value = editor.innerHTML;
          textarea.style.display = 'block';
          editor.hidden = true;
          textarea.focus();
        } else {
          editor.replaceChildren(...safeNodes(textarea.value));
          editor.hidden = false;
          textarea.style.display = '';
          editor.focus();
          sync();
        }
        return;
      }
      if (sourceMode) return;
      editor.focus();
      if (cmd === 'h2' || cmd === 'h3' || cmd === 'blockquote') {
        document.execCommand('formatBlock', false, cmd);
      } else if (cmd === 'link') {
        const url = window.prompt('Link address (https://… or /page)');
        if (url && /^(https?:\/\/|\/(?!\/)|mailto:)/i.test(url.trim())) document.execCommand('createLink', false, url.trim());
      } else {
        document.execCommand(cmd, false);
      }
      sync();
    });
    toolbar.append(b);
  });
  wrap.prepend(toolbar);
  editor.addEventListener('input', sync);
  editor.addEventListener('blur', sync);
  textarea.form?.addEventListener('submit', sync);
}
