<section class="section">
  <div class="container narrow error-page">
    <span class="eyebrow"><?= icon('lock') ?> Members area</span>
    <h1><?= e(t('members.only')) ?></h1>
    <p class="lede"><?= e(t('members.login_to_view')) ?></p>
    <p><a class="btn" href="/login?next=<?= e(rawurlencode(request_path())) ?>">Log in</a> <a class="btn ghost" href="/join">Become a member</a></p>
  </div>
</section>
