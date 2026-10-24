// Media library: set an image's focal point by clicking the photo. The two
// number inputs stay the source of truth (and work without JavaScript); this
// moves a marker and updates the crop previews as they change.
export function initFocal() {
  const stage = document.querySelector('[data-focal-stage]');
  const fields = document.querySelector('[data-focal-fields]');
  if (!stage || !fields) return;
  const x = fields.querySelector('input[name="focal_x"]');
  const y = fields.querySelector('input[name="focal_y"]');
  const marker = stage.querySelector('.focal-marker');
  const previews = [...fields.querySelectorAll('[data-focal-previews] img')];
  if (!x || !y || !marker) return;
  const clamp = (v) => Math.max(0, Math.min(100, Math.round(Number(v) || 0)));
  const paint = () => {
    const fx = clamp(x.value);
    const fy = clamp(y.value);
    marker.hidden = false;
    marker.style.left = `${fx}%`;
    marker.style.top = `${fy}%`;
    previews.forEach((img) => { img.style.objectPosition = `${fx}% ${fy}%`; });
  };
  stage.classList.add('is-active');
  stage.addEventListener('click', (e) => {
    const r = stage.getBoundingClientRect();
    x.value = String(clamp(((e.clientX - r.left) / r.width) * 100));
    y.value = String(clamp(((e.clientY - r.top) / r.height) * 100));
    paint();
  });
  [x, y].forEach((input) => input.addEventListener('input', paint));
  paint();
}
