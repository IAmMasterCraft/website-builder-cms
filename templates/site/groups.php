<?= view('partials/page_hero', ['heading' => 'Affiliated groups', 'intro' => 'Associations and community groups connected with us.', 'crumbs' => ['Groups' => null]]) ?>
<section class="section">
  <div class="container">
    <?php if ($rows): ?>
      <ul class="dir-list">
        <?php foreach ($rows as $g): $logo = media_find($g['logo_media_id'] ? (int) $g['logo_media_id'] : null); ?>
          <li class="dir-item" id="<?= e($g['slug']) ?>">
            <?php if ($logo): ?><?= img_html($logo, '56px', '', 'dir-logo', true, 400) ?><?php endif ?>
            <h2><?= e($g['name']) ?></h2>
            <?php if ($g['group_number']): ?><span class="badge brand tabular"><?= e($g['group_number']) ?></span><?php endif ?>
            <?php if ($g['description']): ?><div class="prose"><?= rich($g['description']) ?></div><?php endif ?>
            <dl>
              <?php if ($g['contact_person'] !== ''): ?><dt>Contact</dt><dd><?= e($g['contact_person']) ?></dd><?php endif ?>
              <?php if ($g['email']): ?><dt>Email</dt><dd><a href="mailto:<?= e($g['email']) ?>"><?= e($g['email']) ?></a></dd><?php endif ?>
              <?php if ($g['country']): ?><dt>Country</dt><dd><?= e($g['country']) ?></dd><?php endif ?>
              <?php if ($g['website']): ?><dt>Website</dt><dd><a href="<?= e($g['website']) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://(www\.)?#', '', $g['website'])) ?></a></dd><?php endif ?>
            </dl>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?><?= view('partials/empty', ['message' => 'No groups listed yet.']) ?><?php endif ?>
    <p class="muted">Represent an association? <a href="/join/group">Register your group</a>.</p>
  </div>
</section>
