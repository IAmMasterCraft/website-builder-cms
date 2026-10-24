<?= view('partials/page_hero', ['heading' => 'Downloads', 'intro' => 'Documents, forms and reports.', 'crumbs' => ['Downloads' => null]]) ?>
<section class="section">
  <div class="container narrow">
    <?php if ($categories): ?><?= view('partials/filters', ['label' => 'Category', 'param' => 'category', 'options' => $categories, 'current' => $category, 'path' => '/downloads', 'query' => $category !== '' ? ['category' => $category] : []]) ?><?php endif ?>
    <?php if ($rows): ?>
      <?php foreach ($rows as $d): $ext = strtoupper(pathinfo((string) $d['path'], PATHINFO_EXTENSION)); $locked = $d['visibility'] === 'members' && !$is_member; ?>
        <div class="file-row" id="download-<?= (int) $d['id'] ?>">
          <span class="file-icon" aria-hidden="true"><?= e($ext) ?></span>
          <div class="file-main">
            <h2 class="h3"><?= e($d['title']) ?></h2>
            <p><?= e($d['summary'] ?? '') ?></p>
            <p class="tabular"><?= e($ext) ?> · <?= e(number_format(((int) $d['size']) / 1024, 0)) ?> KB<?= $d['category'] !== '' ? ' · ' . e($d['category']) : '' ?> · <?= e(pluralise((int) $d['download_count'], 'download')) ?>
              <?php if ($d['visibility'] === 'members'): ?> · <span class="lock"><?= icon('lock') ?> <?= e(t('members.only')) ?></span><?php endif ?></p>
          </div>
          <div class="file-actions">
          <?= view('partials/like_button', ['type' => 'download', 'id' => (int) $d['id'], 'viewer' => $viewer, 'return' => '/downloads' . ($category !== '' ? '?category=' . rawurlencode($category) : ''), 'label' => 'Useful']) ?>
          <?php if ($locked): ?>
            <a class="btn ghost small" href="/login?next=%2Fdownloads"><?= icon('lock') ?> Log in<span class="sr-only"> to download <?= e($d['title']) ?></span></a>
          <?php else: ?>
            <a class="btn small" href="/downloads/<?= (int) $d['id'] ?>/file"><?= icon('download') ?> Download<span class="sr-only"> <?= e($d['title']) ?></span></a>
          <?php endif ?>
          </div>
        </div>
      <?php endforeach ?>
    <?php else: ?>
      <?= view('partials/empty', ['message' => 'No downloads yet.']) ?>
    <?php endif ?>
  </div>
</section>
