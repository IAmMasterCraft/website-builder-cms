<?= view('partials/page_hero', ['heading' => 'Branches', 'intro' => 'Find your nearest branch and when it meets.', 'crumbs' => ['Branches' => null]]) ?>
<section class="section">
  <div class="container">
    <?php if ($rows): ?>
      <ul class="dir-list">
        <?php foreach ($rows as $b): ?>
          <li class="dir-item" id="<?= e($b['slug']) ?>">
            <h2><?= e($b['name']) ?></h2>
            <?php if ($b['region'] !== ''): ?><span class="badge"><?= icon('pin') ?> <?= e($b['region']) ?></span><?php endif ?>
            <?php if ($b['description']): ?><div class="prose"><?= rich($b['description']) ?></div><?php endif ?>
            <dl>
              <?php if ($b['contact_person'] !== ''): ?><dt>Contact</dt><dd><?= e($b['contact_person']) ?></dd><?php endif ?>
              <?php if ($b['email']): ?><dt>Email</dt><dd><a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a></dd><?php endif ?>
              <?php if ($b['phone']): ?><dt>Phone</dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $b['phone'])) ?>"><?= e($b['phone']) ?></a></dd><?php endif ?>
              <?php if ($b['meeting_venue']): ?><dt>Meets</dt><dd><?= e($b['meeting_venue']) ?></dd><?php endif ?>
            </dl>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?><?= view('partials/empty', ['message' => 'No branches listed yet.']) ?><?php endif ?>
  </div>
</section>
