// Live radio player (§6.2). The server says what is playing and how far
// in (offset, computed from its UTC clock); every listener seeks to that
// position plus half the request's round trip, so all browsers play the
// same moment. Every 30 s the player re-syncs and seeks again when it has
// drifted more than 2 s. Browsers block autoplay, so nothing plays until
// the listener presses "Tune in".
const DRIFT_LIMIT = 2;
const RESYNC_MS = 30000;
const players = [];

const fmt = (s) => {
  if (!Number.isFinite(s) || s < 0) s = 0;
  s = Math.floor(s);
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = String(s % 60).padStart(2, '0');
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
};

const countdown = (s) => {
  if (!Number.isFinite(s) || s <= 0) return 'starting now';
  if (s < 60) return `in ${Math.ceil(s)} s`;
  if (s < 3600) return `in ${Math.floor(s / 60)} min ${String(Math.floor(s % 60)).padStart(2, '0')} s`;
  if (s < 86400) return `in ${Math.floor(s / 3600)} h ${Math.floor((s % 3600) / 60)} min`;
  return `in ${Math.floor(s / 86400)} days`;
};

function store(key, value) {
  try { localStorage.setItem(key, value); } catch { /* storage blocked */ }
}
function load(key) {
  try { return localStorage.getItem(key); } catch { return null; }
}

class Player {
  constructor(root) {
    this.root = root;
    this.api = root.dataset.api;
    const q = (s) => root.querySelector(s);
    this.ui = {
      audio: q('[data-rp-audio]'), video: q('[data-rp-video]'), cover: q('[data-rp-cover]'), fallback: q('[data-rp-fallback]'),
      tune: q('[data-rp-tune]'), status: q('[data-rp-status]'), title: q('[data-rp-title]'), artist: q('[data-rp-artist]'),
      desc: q('[data-rp-desc]'), progressWrap: q('[data-rp-progress-wrap]'), progress: q('[data-rp-progress]'),
      elapsed: q('[data-rp-elapsed]'), duration: q('[data-rp-duration]'), controls: q('[data-rp-controls]'),
      play: q('[data-rp-play]'), volume: q('[data-rp-volume]'), next: q('[data-rp-next]'), nextTitle: q('[data-rp-next-title]'),
      nextArtist: q('[data-rp-next-artist]'), countdown: q('[data-rp-countdown]'), message: q('[data-rp-message]'), drift: q('[data-rp-drift]'),
    };
    this.state = null;
    this.anchor = null;
    this.el = null;
    this.wanted = false;
    this.boundary = null;
    this.retry = null;
    this.syncing = null;

    this.ui.tune.hidden = false;
    this.ui.controls.hidden = false;
    const vol = Number(load('gather-radio-volume'));
    if (Number.isFinite(vol) && load('gather-radio-volume') !== null) this.ui.volume.value = String(vol);
    this.applyVolume();

    this.ui.tune.addEventListener('click', () => this.start());
    this.ui.play.addEventListener('click', () => (this.wanted ? this.stop() : this.start()));
    this.ui.volume.addEventListener('input', () => { this.applyVolume(); store('gather-radio-volume', this.ui.volume.value); });
    [this.ui.audio, this.ui.video].forEach((el) => {
      el.addEventListener('ended', () => { if (this.wanted) this.sync(); });
      el.addEventListener('error', () => {
        if (!this.wanted || !el.getAttribute('src')) return;
        this.say('The stream could not be loaded. Trying again…');
        clearTimeout(this.retry);
        this.retry = setTimeout(() => { el.removeAttribute('src'); delete el.dataset.src; this.sync(); }, 5000);
      });
    });

    this.refresh();
    setInterval(() => (this.wanted ? this.sync() : this.refresh()), RESYNC_MS);
    setInterval(() => this.tick(), 250);
  }

  say(text) { this.ui.message.textContent = text; }

  applyVolume() {
    const v = Number(this.ui.volume.value);
    this.ui.audio.volume = v;
    this.ui.video.volume = v;
  }

  async fetchState() {
    const t0 = performance.now();
    const res = await fetch(this.api, { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const t1 = performance.now();
    if (!res.ok) throw new Error(`Status ${res.status}`);
    const data = await res.json();
    if (!data || typeof data.status !== 'string') throw new Error('Bad response');
    data.receivedAt = t1;
    data.latency = (t1 - t0) / 2;
    return data;
  }

  /** Where the live programme is right now, in seconds into the current item. */
  livePosition() {
    return this.anchor ? (performance.now() - this.anchor.startAt) / 1000 : 0;
  }

  apply(data) {
    this.state = data;
    const on = data.status !== 'offline' && data.media;
    // The server's offset is the position at its clock; half the round trip
    // later (when the answer arrives) the programme is that much further on.
    this.anchor = on ? { url: data.media.url, startAt: data.receivedAt - (data.offset * 1000 + data.latency), duration: data.media.duration } : null;
    this.root.classList.toggle('is-offline', !on);
    this.root.classList.toggle('is-video', Boolean(on && data.media.media_type === 'video'));
    this.ui.status.textContent = on ? (data.status === 'scheduled' ? 'On air now' : 'On air · non-stop') : 'Off air';
    this.ui.title.textContent = on ? data.media.title : 'We are off air';
    this.ui.artist.textContent = on ? (data.media.artist || '') : (data.next ? 'Our next broadcast is coming up.' : 'Check back soon for our next broadcast.');
    if (this.ui.desc) this.ui.desc.textContent = on ? (data.media.description || '').slice(0, 220) : '';
    if (on && data.media.cover_image) {
      if (this.ui.cover.getAttribute('src') !== data.media.cover_image) this.ui.cover.src = data.media.cover_image;
      this.ui.cover.hidden = false;
      this.ui.fallback.hidden = true;
    } else {
      this.ui.cover.hidden = true;
      this.ui.fallback.hidden = false;
    }
    this.ui.progressWrap.hidden = !on;
    this.ui.duration.textContent = on ? fmt(data.media.duration) : '';
    this.ui.play.disabled = !on;
    this.ui.tune.disabled = !on;
    if (data.next) {
      this.ui.next.hidden = false;
      this.ui.next.querySelector('.rp-next-label').textContent = on ? 'Up next' : 'Next on air';
      this.ui.nextTitle.textContent = data.next.title;
      this.ui.nextArtist.textContent = data.next.artist ? ` · ${data.next.artist}` : '';
    } else {
      this.ui.next.hidden = true;
    }
    clearTimeout(this.boundary);
    const wait = Math.max(0.5, Math.min(data.next_check_in ?? 30, on ? data.remaining : 60)) * 1000 + 300;
    this.boundary = setTimeout(() => (this.wanted ? this.sync() : this.refresh()), wait);
    this.tick();
  }

  tick() {
    const s = this.state;
    if (!s) return;
    if (this.anchor && this.anchor.duration) {
      const pos = Math.min(Math.max(this.livePosition(), 0), this.anchor.duration);
      this.ui.progress.value = pos / this.anchor.duration;
      this.ui.elapsed.textContent = fmt(pos);
    }
    if (s.next) {
      const left = s.next.starts_in - (performance.now() - s.receivedAt) / 1000;
      this.ui.countdown.textContent = countdown(left);
    }
  }

  async refresh() {
    try {
      this.apply(await this.fetchState());
    } catch {
      /* keep the server-rendered state */
    }
  }

  async start() {
    players.forEach((p) => { if (p !== this) p.stop(); });
    document.querySelectorAll('audio, video').forEach((m) => { if (!this.root.contains(m)) m.pause(); });
    this.wanted = true;
    this.say('Tuning in…');
    await this.sync();
  }

  stop() {
    this.wanted = false;
    this.el?.pause();
    this.setPlaying(false);
    this.ui.drift.textContent = '';
    this.say('');
  }

  setPlaying(on) {
    this.ui.play.setAttribute('aria-pressed', String(on));
    this.ui.play.querySelector('.rp-play-label').textContent = on ? 'Pause' : 'Play';
    this.root.classList.toggle('is-playing', on);
    if (on) this.ui.tune.hidden = true;
  }

  waitForMetadata(el) {
    if (el.readyState >= 1) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const done = (ok) => { clearTimeout(t); el.removeEventListener('loadedmetadata', onMeta); el.removeEventListener('error', onErr); ok ? resolve() : reject(new Error('media')); };
      const onMeta = () => done(true);
      const onErr = () => done(false);
      const t = setTimeout(() => done(false), 15000);
      el.addEventListener('loadedmetadata', onMeta);
      el.addEventListener('error', onErr);
    });
  }

  async sync() {
    if (this.syncing) return this.syncing;
    this.syncing = this.doSync().finally(() => { this.syncing = null; });
    return this.syncing;
  }

  async doSync() {
    let data;
    try {
      data = await this.fetchState();
    } catch {
      this.say('Connection problem. Trying again…');
      clearTimeout(this.retry);
      this.retry = setTimeout(() => { if (this.wanted) this.sync(); }, 5000);
      return;
    }
    this.apply(data);
    if (!this.wanted) return;
    if (data.status === 'offline' || !data.media) {
      this.el?.pause();
      this.setPlaying(false);
      this.say(data.next ? `Off air. Next: ${data.next.title}, ${countdown(data.next.starts_in)}.` : 'The station is off air.');
      return;
    }
    const isVideo = data.media.media_type === 'video';
    const el = isVideo ? this.ui.video : this.ui.audio;
    const other = isVideo ? this.ui.audio : this.ui.video;
    if (other.getAttribute('src')) { other.pause(); other.removeAttribute('src'); delete other.dataset.src; other.load(); }
    this.ui.video.hidden = !isVideo;
    try {
      if (el.dataset.src !== data.media.url) {
        el.preload = 'auto';
        el.src = data.media.url;
        el.dataset.src = data.media.url;
        this.applyVolume();
        await this.waitForMetadata(el);
        el.currentTime = Math.min(Math.max(this.livePosition(), 0), (el.duration || data.media.duration) - 0.25);
      } else {
        const drift = el.currentTime - this.livePosition();
        if (Math.abs(drift) > DRIFT_LIMIT || el.paused) {
          el.currentTime = Math.max(this.livePosition(), 0);
          if (Math.abs(drift) > DRIFT_LIMIT) this.ui.drift.textContent = `Re-synced (was ${Math.abs(drift).toFixed(1)} s ${drift > 0 ? 'ahead' : 'behind'})`;
        }
      }
      this.el = el;
      await el.play();
    } catch (err) {
      if (err && err.name === 'NotAllowedError') {
        this.wanted = false;
        this.setPlaying(false);
        this.ui.tune.hidden = false;
        this.say('Press “Tune in” to listen.');
      } else {
        this.say('The programme could not be played. Trying again…');
        clearTimeout(this.retry);
        this.retry = setTimeout(() => { if (this.wanted) { delete el.dataset.src; this.sync(); } }, 5000);
      }
      return;
    }
    this.setPlaying(true);
    this.say('');
    // Buffering after the seek takes time: correct once more when playback really starts.
    const correct = () => {
      if (this.el === el && Math.abs(el.currentTime - this.livePosition()) > 0.5) el.currentTime = this.livePosition();
    };
    if (el.readyState >= 3) correct(); else el.addEventListener('playing', correct, { once: true });
  }
}

export function initRadio() {
  document.querySelectorAll('[data-radio-player]').forEach((root) => players.push(new Player(root)));
  if (!players.length) return;
  // Playing something from the on-demand library stops the live player.
  document.addEventListener('play', (e) => {
    const t = e.target;
    if (t instanceof HTMLMediaElement && !t.closest('[data-radio-player]')) players.forEach((p) => p.stop());
  }, true);
  // Expose sync state for automated checks (read-only snapshot).
  window.gatherRadio = () => players.map((p) => ({ wanted: p.wanted, url: p.anchor?.url ?? null, live: p.livePosition(), current: p.el ? p.el.currentTime : null, paused: p.el ? p.el.paused : true }));
}
