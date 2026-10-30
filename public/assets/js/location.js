// Cascading location fields (Addendum C4). Progressive enhancement of
// partials/location_field.php: the server already renders the right lists
// for the chosen country/state; this module loads the next list as soon as
// a choice is made (one JSON file per country, fetched once and cached),
// adds type-to-filter on long lists and suggests a country from browser
// hints only (time zone, languages, CDN header). No IP lookups.

const FILTER_MIN = 15;
const cache = new Map();
let customPromise = null;
let countriesPromise = null;

function settings(el) {
  const host = el.closest('[data-geo-v]') || document.body;
  let labels = {};
  try { labels = JSON.parse(host.dataset.geoLabels || '{}'); } catch { labels = {}; }
  return { v: host.dataset.geoV || '0', labels, custom: host.dataset.geoCustom || '', header: host.dataset.geoHeader || '' };
}

async function getJson(url) {
  const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  if (!res.ok) throw new Error('HTTP ' + res.status);
  return res.json();
}

function loadCountry(cc, s) {
  if (!/^[A-Z]{2}$/.test(cc)) return Promise.resolve({ a1: [], a2: {} });
  if (!cache.has(cc)) {
    const p = getJson('/assets/geo/' + cc.toLowerCase() + '.json?v=' + encodeURIComponent(s.v))
      .catch(() => ({ a1: [], a2: {} })) // countries without a file (or offline): free text
      .then((d) => ({ a1: Array.isArray(d.a1) ? d.a1 : [], a2: d.a2 && typeof d.a2 === 'object' ? d.a2 : {} }));
    cache.set(cc, p);
  }
  return cache.get(cc);
}

function loadCustom(s) {
  if (!s.custom) return Promise.resolve({});
  customPromise ??= getJson(s.custom).then((d) => (d && d.areas) || {}).catch(() => ({}));
  return customPromise;
}

async function areasFor(cc, a1, s) {
  const [data, custom] = await Promise.all([loadCountry(cc, s), loadCustom(s)]);
  const list = [...(data.a2[a1] || []), ...((custom[cc] && custom[cc][a1]) || [])];
  return list.sort((x, y) => x[1].localeCompare(y[1], 'en'));
}

function labelFor(field, level, cc, s) {
  const fixed = level === 1 ? field.dataset.l1Label : field.dataset.l2Label;
  if (fixed) return fixed;
  const pair = s.labels[cc] || ['Region', 'Area'];
  return level === 1 ? pair[0] + (field.dataset.l1Suffix || '') : pair[1];
}

// ---- Options and type-to-filter ------------------------------------------------------

function setOptions(select, items) {
  select._items = items;
  renderOptions(select, '');
  const filter = select._filter;
  if (filter) {
    filter.value = '';
    filter.hidden = items.length < FILTER_MIN;
  } else if (items.length >= FILTER_MIN) {
    addFilter(select);
  }
}

function renderOptions(select, query) {
  const current = select.value;
  const q = query.trim().toLowerCase();
  const items = select._items || [];
  select.replaceChildren();
  const blank = document.createElement('option');
  blank.value = '';
  blank.textContent = q ? 'Matches for “' + query.trim() + '”' : '—';
  select.append(blank);
  for (const [code, name] of items) {
    if (q && !name.toLowerCase().includes(q) && code !== current) continue;
    const o = document.createElement('option');
    o.value = code;
    o.textContent = name;
    if (code === current) o.selected = true;
    select.append(o);
  }
}

function addFilter(select, labelText) {
  const input = document.createElement('input');
  input.type = 'search';
  input.className = 'loc-filter';
  input.autocomplete = 'off';
  input.placeholder = 'Type to filter…';
  input.setAttribute('aria-controls', select.id);
  const name = labelText || (select.closest('.field')?.querySelector('[data-loc-label-text], label')?.textContent || 'list').trim();
  input.setAttribute('aria-label', 'Filter the list: ' + name);
  input.addEventListener('input', () => {
    if (select._items) renderOptions(select, input.value);
    else filterCountries(select, input.value);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'Enter') {
      e.preventDefault();
      const first = [...select.options].find((o) => o.value && !o.hidden && o.parentElement?.hidden !== true);
      if (first && e.key === 'Enter') { select.value = first.value; select.dispatchEvent(new Event('change', { bubbles: true })); }
      select.focus();
    }
  });
  select.before(input);
  select._filter = input;
}

// The country list keeps its optgroups: filter by rebuilding "All countries".
function filterCountries(select, query) {
  const all = select.querySelector('optgroup:not([data-loc-suggested])');
  if (!all) return;
  all._items ??= [...all.children].map((o) => [o.value, o.textContent]);
  const q = query.trim().toLowerCase();
  const current = select.value;
  all.replaceChildren();
  for (const [code, name] of all._items) {
    if (q && !name.toLowerCase().includes(q) && code !== current) continue;
    const o = document.createElement('option');
    o.value = code;
    o.textContent = name;
    if (code === current) o.selected = true;
    all.append(o);
  }
}

// ---- One location field -----------------------------------------------------------------

function levelParts(field, n) {
  const wrap = field.querySelector('[data-loc-level="' + n + '"]');
  if (!wrap) return null;
  return {
    wrap,
    select: wrap.querySelector('select[data-loc-select]'),
    text: wrap.querySelector('input[data-loc-text]'),
    label: wrap.querySelector('label[data-loc-label]'),
    labelText: wrap.querySelector('[data-loc-label-text]'),
  };
}

function useList(part, items) {
  const list = items.length > 0;
  part.select.hidden = !list;
  part.select.disabled = !list;
  if (part.select._filter) part.select._filter.hidden = !list || items.length < FILTER_MIN;
  part.text.hidden = list;
  part.text.disabled = list;
  part.label.htmlFor = list ? part.select.id : part.text.id;
  if (list) setOptions(part.select, items);
}

async function showLevel1(field, s, keep) {
  const l1 = levelParts(field, 1);
  if (!l1) return;
  const cc = field.dataset.fixed || field.querySelector('[data-loc-country]')?.value || '';
  const l2 = levelParts(field, 2);
  if (!keep) {
    l1.select.value = '';
    l1.text.value = '';
    if (l2) { l2.select.value = ''; l2.text.value = ''; l2.wrap.hidden = true; }
  }
  if (!cc) { l1.wrap.hidden = true; return; }
  l1.labelText.textContent = labelFor(field, 1, cc, s);
  if (l2) l2.labelText.textContent = labelFor(field, 2, cc, s);
  const data = await loadCountry(cc, s);
  useList(l1, data.a1);
  l1.wrap.hidden = false;
  if (keep && l2) await showLevel2(field, s, true);
}

async function showLevel2(field, s, keep) {
  const l1 = levelParts(field, 1);
  const l2 = levelParts(field, 2);
  if (!l1 || !l2) return;
  const cc = field.dataset.fixed || field.querySelector('[data-loc-country]')?.value || '';
  if (!keep) { l2.select.value = ''; l2.text.value = ''; }
  const a1 = l1.select.disabled ? '' : l1.select.value;
  const typed = l1.select.disabled && l1.text.value.trim() !== '';
  if (!cc || (!a1 && !typed)) { l2.wrap.hidden = true; return; }
  useList(l2, a1 ? await areasFor(cc, a1, s) : []);
  l2.wrap.hidden = false;
}

function initField(field) {
  const s = settings(field);
  field.querySelector('[data-loc-refresh]')?.remove();
  const country = field.querySelector('[data-loc-country]');
  if (country) {
    addFilter(country, field.querySelector('label[for="' + country.id + '"]')?.textContent || 'countries');
    country.addEventListener('change', () => {
      country.dataset.touched = '1';
      const guess = field.querySelector('[data-loc-guess]');
      if (guess) guess.textContent = '';
      showLevel1(field, s, false);
    });
  }
  const l1 = levelParts(field, 1);
  if (l1) {
    l1.select.addEventListener('change', () => showLevel2(field, s, false));
    l1.text.addEventListener('change', () => showLevel2(field, s, false));
  }
  // Upgrade the server-rendered lists (filter box, admin-added areas) without losing the choice.
  if (l1 && !l1.wrap.hidden) showLevel1(field, s, true);
}

// ---- Country hint -----------------------------------------------------------------------------

function loadCountries(s) {
  countriesPromise ??= getJson('/assets/geo/countries.json?v=' + encodeURIComponent(s.v)).catch(() => null);
  return countriesPromise;
}

async function suggest(fields) {
  const s = settings(fields[0]);
  const data = await loadCountries(s);
  if (!data) return;
  const known = new Map(data.countries.map((c) => [c[0], c[1]]));
  const sources = [];
  try {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (tz && data.tz[tz]) sources.push(data.tz[tz]);
  } catch { /* no Intl time zone */ }
  const langs = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || ''])
    .map((l) => (String(l).split('-').find((part, i) => i > 0 && /^[A-Za-z]{2}$/.test(part)) || '').toUpperCase())
    .filter((cc) => known.has(cc));
  if (langs[0]) sources.push(langs[0]);
  if (s.header && known.has(s.header)) sources.push(s.header);
  const best = sources[0];
  if (!best) return;
  // Confident = two independent hints agree (e.g. time zone and language region).
  const confident = sources.slice(1).includes(best);
  for (const field of fields) {
    const select = field.querySelector('[data-loc-country]');
    const group = select?.querySelector('[data-loc-suggested]');
    if (!group) continue;
    const o = document.createElement('option');
    o.value = best;
    o.textContent = known.get(best);
    group.replaceChildren(o);
    group.hidden = false;
    group.disabled = false;
    if (field.hasAttribute('data-loc-hint') && confident && !select.value && !select.dataset.touched && !formHasValues(field)) {
      select.value = best;
      const note = field.querySelector('[data-loc-guess]');
      if (note) note.textContent = 'We guessed ' + known.get(best) + ' from your device settings. Change it if that is wrong.';
      showLevel1(field, s, false);
    }
  }
}

// Only pre-fill a form nobody has started (no typed values anywhere in it).
function formHasValues(field) {
  const form = field.closest('form');
  if (!form) return false;
  return [...form.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"]')].some((i) => i.value.trim() !== '' && !i.closest('.hp'));
}

const fields = [...document.querySelectorAll('[data-loc]')];
fields.forEach(initField);
const withCountry = fields.filter((f) => f.querySelector('[data-loc-country]'));
if (withCountry.length && fields.some((f) => f.hasAttribute('data-loc-hint'))) suggest(withCountry);
