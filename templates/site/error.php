<?php /** @var int $status @var string $message */ ?>
<section class="section">
  <div class="container narrow error-page">
    <p class="error-code tabular" aria-hidden="true"><?= (int) $status ?></p>
    <h1><?= e($status === 404 ? 'We can’t find that page' : \Core\HttpException::reason($status)) ?></h1>
    <p class="lede"><?= e($status === 404 ? 'It may have moved, or the link may be out of date. Try searching, or use one of the links below.' : $message) ?></p>
    <?php if ($status === 404 || $status === 403): ?>
      <form class="search-form" role="search" method="get" action="/search">
        <label for="q404" class="sr-only">Search the site</label>
        <input type="search" id="q404" name="q" placeholder="<?= e(t('search.placeholder')) ?>" maxlength="100">
        <button class="btn" type="submit"><?= icon('search') ?> Search</button>
      </form>
    <?php endif ?>
    <ul class="error-links">
      <li><a class="chip" href="/">Home</a></li>
      <li><a class="chip" href="/news">News</a></li>
      <li><a class="chip" href="/events">Events</a></li>
      <li><a class="chip" href="/gallery">Gallery</a></li>
      <?php if ($status === 403 && current_user() === null): ?><li><a class="chip" href="/login">Log in</a></li><?php endif ?>
    </ul>
  </div>
</section>
