<?php /** @var array $events @var array $posts @var array $albums @var ?array $hero_image @var array $stats */ ?>
<section class="hero" aria-labelledby="hero-title">
  <div class="container">
    <div>
      <span class="eyebrow">Welcome</span>
      <h1 id="hero-title"><?= e(setting('site.name')) ?></h1>
      <p class="lede"><?= e(setting('site.tagline')) ?></p>
      <div class="hero-actions">
        <a class="btn highlight" href="/events">See what’s on <?= icon('arrow-right') ?></a>
        <a class="btn on-dark" href="/join">Become a member</a>
      </div>
    </div>
    <?php if ($hero_image): ?>
      <div class="hero-media"><?= img_html($hero_image, '(min-width: 56rem) 34rem, 100vw', '', '', false, 1600) ?></div>
    <?php endif ?>
  </div>
</section>

<section class="section tight soft" aria-label="At a glance">
  <div class="container stats">
    <div class="stat"><strong><?= (int) $stats['events'] ?></strong><span>events organised</span></div>
    <div class="stat"><strong><?= (int) $stats['branches'] ?></strong><span>local branches</span></div>
    <div class="stat"><strong><?= (int) $stats['groups'] ?></strong><span>affiliated groups</span></div>
    <div class="stat"><strong><?= (int) $stats['photos'] ?></strong><span>photos shared</span></div>
  </div>
</section>

<section class="section" aria-labelledby="events-title">
  <div class="container">
    <div class="section-head">
      <div><span class="eyebrow">Get together</span><h2 id="events-title">Upcoming events</h2></div>
      <a class="link-arrow" href="/events/calendar">Open the calendar <?= icon('arrow-right') ?></a>
    </div>
    <?php if ($events): ?>
      <ul class="event-list"><?php foreach ($events as $event): ?><?= view('partials/event_row', ['event' => $event]) ?><?php endforeach ?></ul>
    <?php else: ?>
      <?= view('partials/empty', ['message' => 'No upcoming events right now. New dates are added regularly.']) ?>
    <?php endif ?>
  </div>
</section>

<section class="section soft" aria-labelledby="news-title">
  <div class="container">
    <div class="section-head">
      <div><span class="eyebrow">Latest</span><h2 id="news-title">News and stories</h2></div>
      <a class="link-arrow" href="/news">All news <?= icon('arrow-right') ?></a>
    </div>
    <?php if ($posts): ?>
      <div class="grid"><?php foreach ($posts as $post): ?><?= view('partials/post_card', ['post' => $post]) ?><?php endforeach ?></div>
    <?php else: ?>
      <?= view('partials/empty') ?>
    <?php endif ?>
  </div>
</section>

<?php if ($albums): ?>
<section class="section" aria-labelledby="gallery-title">
  <div class="container">
    <div class="section-head">
      <div><span class="eyebrow">Moments</span><h2 id="gallery-title">From the gallery</h2></div>
      <a class="link-arrow" href="/gallery">View all albums <?= icon('arrow-right') ?></a>
    </div>
    <div class="grid">
      <?php foreach ($albums as $a): ?>
        <article class="card album-card">
          <div class="card-media"><?= img_html(media_find($a['cover_media_id'] ? (int) $a['cover_media_id'] : null), '(min-width: 72rem) 22rem, (min-width: 40rem) 45vw, 100vw', '') ?></div>
          <div class="card-body"><h3><a href="/gallery/<?= e($a['slug']) ?>"><?= e($a['title']) ?></a></h3><p><?= e(excerpt($a['summary'], 100)) ?></p></div>
        </article>
      <?php endforeach ?>
    </div>
  </div>
</section>
<?php endif ?>

<section class="section tight">
  <div class="container">
    <div class="cta-band">
      <div><h2>Be part of it</h2><p>Membership connects you with events, news and neighbours who care about the same place you do.</p></div>
      <a class="btn highlight" href="/join">Join us today</a>
    </div>
  </div>
</section>
