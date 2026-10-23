// Square photo crop in the browser (§4.1). Without JavaScript the server
// centre-crops instead. The cropped JPEG replaces the chosen file in the
// file input, so the form submits normally.
const SIZE = 800;

document.querySelectorAll('[data-photo-crop]').forEach((box) => {
  const input = box.querySelector('input[type="file"]');
  if (!input || typeof DataTransfer !== 'function' || !HTMLCanvasElement.prototype.toBlob) return;

  const stage = document.createElement('div');
  stage.className = 'crop-stage';
  stage.tabIndex = 0;
  stage.setAttribute('role', 'group');
  stage.setAttribute('aria-label', 'Crop your photo: drag the picture or use the arrow keys to move it; plus and minus keys zoom.');
  const view = document.createElement('canvas');
  view.width = 320;
  view.height = 320;
  view.className = 'crop-canvas';
  view.setAttribute('aria-hidden', 'true');
  stage.append(view);
  const zoomLabel = document.createElement('label');
  zoomLabel.className = 'crop-zoom';
  zoomLabel.textContent = 'Zoom ';
  const zoom = document.createElement('input');
  zoom.type = 'range';
  zoom.min = '1';
  zoom.max = '3';
  zoom.step = '0.01';
  zoom.value = '1';
  zoomLabel.append(zoom);
  const status = document.createElement('p');
  status.className = 'hint';
  status.setAttribute('role', 'status');
  const wrap = document.createElement('div');
  wrap.className = 'crop-ui';
  wrap.hidden = true;
  wrap.append(stage, zoomLabel, status);
  box.append(wrap);

  let img = null;
  let originalName = 'photo.jpg';
  let scale = 1; // zoom factor on top of "cover"
  let cx = 0.5; // centre of the crop, as a fraction of the image
  let cy = 0.5;
  let timer = null;
  let replacing = false;

  const base = () => Math.min(img.naturalWidth, img.naturalHeight); // side of the square at zoom 1
  function clamp() {
    const side = base() / scale;
    const hw = side / 2 / img.naturalWidth;
    const hh = side / 2 / img.naturalHeight;
    cx = Math.min(1 - hw, Math.max(hw, cx));
    cy = Math.min(1 - hh, Math.max(hh, cy));
  }
  function source() {
    const side = base() / scale;
    return [cx * img.naturalWidth - side / 2, cy * img.naturalHeight - side / 2, side];
  }
  function draw() {
    if (!img) return;
    clamp();
    const [sx, sy, side] = source();
    const ctx = view.getContext('2d');
    ctx.clearRect(0, 0, view.width, view.height);
    ctx.drawImage(img, sx, sy, side, side, 0, 0, view.width, view.height);
    window.clearTimeout(timer);
    timer = window.setTimeout(commit, 250);
  }
  function commit() {
    const [sx, sy, side] = source();
    const out = document.createElement('canvas');
    out.width = Math.min(SIZE, Math.round(side));
    out.height = out.width;
    out.getContext('2d').drawImage(img, sx, sy, side, side, 0, 0, out.width, out.height);
    out.toBlob((blob) => {
      if (!blob) return;
      const dt = new DataTransfer();
      dt.items.add(new File([blob], originalName.replace(/\.[^.]+$/, '') + '-square.jpg', { type: 'image/jpeg' }));
      replacing = true;
      input.files = dt.files;
      replacing = false;
      status.textContent = 'Cropped to a square. This is how your photo will look on your card.';
    }, 'image/jpeg', 0.9);
  }

  input.addEventListener('change', () => {
    if (replacing) return;
    const file = input.files?.[0];
    if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) { wrap.hidden = true; img = null; return; }
    originalName = file.name;
    const url = URL.createObjectURL(file);
    const next = new Image();
    next.onload = () => {
      URL.revokeObjectURL(url);
      if (next.naturalWidth < 200 || next.naturalHeight < 200) {
        status.textContent = 'This photo is quite small; a larger one will look better on your card.';
      }
      img = next;
      scale = 1;
      cx = 0.5;
      cy = 0.4;
      zoom.value = '1';
      wrap.hidden = false;
      draw();
    };
    next.onerror = () => { URL.revokeObjectURL(url); wrap.hidden = true; };
    next.src = url;
  });

  zoom.addEventListener('input', () => { scale = Number(zoom.value); draw(); });

  let drag = null;
  stage.addEventListener('pointerdown', (e) => {
    if (!img) return;
    drag = { x: e.clientX, y: e.clientY, cx, cy };
    stage.setPointerCapture(e.pointerId);
  });
  stage.addEventListener('pointermove', (e) => {
    if (!drag) return;
    const side = base() / scale;
    const perPx = side / view.getBoundingClientRect().width;
    cx = drag.cx - ((e.clientX - drag.x) * perPx) / img.naturalWidth;
    cy = drag.cy - ((e.clientY - drag.y) * perPx) / img.naturalHeight;
    draw();
  });
  stage.addEventListener('pointerup', () => { drag = null; });
  stage.addEventListener('pointercancel', () => { drag = null; });
  stage.addEventListener('keydown', (e) => {
    if (!img) return;
    const step = 0.02;
    const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    if (moves[e.key]) {
      e.preventDefault();
      cx += moves[e.key][0];
      cy += moves[e.key][1];
      draw();
    } else if (e.key === '+' || e.key === '=' || e.key === '-') {
      e.preventDefault();
      scale = Math.min(3, Math.max(1, scale + (e.key === '-' ? -0.1 : 0.1)));
      zoom.value = String(scale);
      draw();
    }
  });
});
