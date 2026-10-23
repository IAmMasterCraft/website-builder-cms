<?php
/** @var array $page @var array $config @var array $revisions @var bool $unpublished @var string $public_url */
$groups = [];
foreach (builder_section_types() as $key => $class) {
    $groups[$class::GROUP][$key] = $class;
}
$statusLabel = match (true) {
    $page['status'] === 'draft' => 'Draft page',
    $page['status'] === 'scheduled' => 'Scheduled',
    default => 'Published',
};
?>
<div class="builder" data-builder>
  <script type="application/json" id="builder-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?></script>
  <div class="builder-bar">
    <div class="builder-title">
      <a class="icon-btn" href="/admin/pages"><?= icon('chevron-left') ?><span class="sr-only">Back to pages</span></a>
      <div>
        <h1><?= e($page['title']) ?></h1>
        <p class="muted"><span class="badge"><?= e($statusLabel) ?></span> <span data-unpublished<?= $unpublished ? '' : ' hidden' ?> class="badge warn">Changes not published</span> <span class="save-state" data-save-state role="status">All changes saved</span></p>
      </div>
    </div>
    <div class="builder-actions">
      <div class="segmented small" role="group" aria-label="Preview width">
        <button type="button" data-device="100%" aria-pressed="true">Desktop</button>
        <button type="button" data-device="820px" aria-pressed="false">Tablet</button>
        <button type="button" data-device="390px" aria-pressed="false">Mobile</button>
      </div>
      <a class="btn ghost small" href="/admin/pages/<?= (int) $page['id'] ?>/edit"><?= icon('settings') ?> Page settings</a>
      <a class="btn ghost small" href="<?= e($public_url) ?>" target="_blank" rel="noopener"><?= icon('external') ?> View page</a>
      <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/publish" data-publish>
        <?= csrf_field() ?>
        <input type="hidden" name="revision" value="<?= (int) $page['revision'] ?>" data-revision-input>
        <button class="btn accent small" type="submit"><?= icon('check') ?> Publish</button>
      </form>
    </div>
  </div>
  <div class="builder-conflict flash error" data-conflict hidden role="alert"><?= icon('alert') ?><span data-conflict-text></span> <a class="btn small" href="/admin/pages/<?= (int) $page['id'] ?>/builder">Reload</a></div>
  <noscript><div class="flash error"><?= icon('alert') ?><span>The page builder needs JavaScript. You can still change the page’s title, address and visibility in <a href="/admin/pages/<?= (int) $page['id'] ?>/edit">Page settings</a>, and restore revisions below.</span></div></noscript>

  <div class="builder-body">
    <div class="builder-canvas">
      <div class="canvas-frame" data-canvas><iframe src="/admin/pages/<?= (int) $page['id'] ?>/preview?frame=1" title="Live preview of the page" data-preview></iframe></div>
    </div>

    <aside class="builder-panel" aria-label="Page builder">
      <div class="panel-tabs" role="tablist" aria-label="Builder panels">
        <button type="button" role="tab" id="tab-sections" aria-controls="pane-sections" aria-selected="true">Sections</button>
        <button type="button" role="tab" id="tab-history" aria-controls="pane-history" aria-selected="false" tabindex="-1">History</button>
      </div>

      <div class="panel-pane" id="pane-sections" role="tabpanel" aria-labelledby="tab-sections">
        <div data-list-view>
          <p class="hint">Drag sections to reorder, or use the arrow buttons. Click a section here or in the preview to edit it.</p>
          <ol class="section-list" data-section-list aria-label="Sections on this page"></ol>
          <p class="empty-list muted" data-empty hidden>This page has no sections yet. Add one to get started.</p>
          <div class="panel-buttons">
            <button type="button" class="btn small" data-open-picker><?= icon('plus') ?> Add section</button>
            <button type="button" class="btn small ghost" data-save-page-template><?= icon('layers') ?> Save page as template</button>
          </div>
        </div>
        <div data-edit-view hidden>
          <div class="edit-head">
            <button type="button" class="btn small ghost" data-back><?= icon('chevron-left') ?> All sections</button>
            <h2 data-edit-title tabindex="-1">Section</h2>
          </div>
          <div class="panel-tabs sub" role="tablist" aria-label="Section settings">
            <button type="button" role="tab" id="tab-content" aria-controls="pane-content" aria-selected="true">Content</button>
            <button type="button" role="tab" id="tab-style" aria-controls="pane-style" aria-selected="false" tabindex="-1">Style</button>
          </div>
          <div class="settings-form" id="pane-content" role="tabpanel" aria-labelledby="tab-content" data-settings-form></div>
          <div class="settings-form" id="pane-style" role="tabpanel" aria-labelledby="tab-style" data-style-form hidden></div>
          <div class="panel-buttons">
            <button type="button" class="btn small ghost" data-edit-duplicate><?= icon('layers') ?> Duplicate</button>
            <button type="button" class="btn small ghost" data-edit-template><?= icon('file') ?> Save as template</button>
            <button type="button" class="btn small danger-ghost" data-edit-delete><?= icon('trash') ?> Delete</button>
          </div>
        </div>
      </div>

      <div class="panel-pane" id="pane-history" role="tabpanel" aria-labelledby="tab-history" hidden>
        <p class="hint">The last <?= BUILDER_MAX_REVISIONS ?> versions of this page. Restoring makes a new draft; publish it to make it live.</p>
        <?php if ($revisions): ?>
          <ol class="revision-list">
            <?php foreach ($revisions as $r): ?>
              <li>
                <div>
                  <strong class="tabular"><?= e(fmt_date((string) $r['updated_at'], 'j M Y, H:i')) ?></strong>
                  <?php if ($r['is_live']): ?><span class="badge ok">Live</span><?php endif ?>
                  <?php if ($r['is_draft']): ?><span class="badge warn">Current draft</span><?php endif ?>
                  <br><span class="muted"><?= e($r['author'] ?? 'Unknown') ?><?= $r['note'] !== '' ? ' · ' . e($r['note']) : '' ?></span>
                </div>
                <div class="revision-actions">
                  <a class="btn small ghost" href="/admin/pages/<?= (int) $page['id'] ?>/preview?revision=<?= (int) $r['id'] ?>" target="_blank" rel="noopener">Preview<span class="sr-only"> version from <?= e(fmt_date((string) $r['updated_at'], 'j M Y, H:i')) ?></span></a>
                  <?php if (!$r['is_draft']): ?>
                    <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/revisions/<?= (int) $r['id'] ?>/restore" data-confirm="Restore this version as a new draft? Your current draft stays in the history.">
                      <?= csrf_field() ?><input type="hidden" name="revision" value="<?= (int) $page['revision'] ?>" data-revision-input>
                      <button class="btn small" type="submit">Restore<span class="sr-only"> version from <?= e(fmt_date((string) $r['updated_at'], 'j M Y, H:i')) ?></span></button>
                    </form>
                  <?php endif ?>
                </div>
              </li>
            <?php endforeach ?>
          </ol>
        <?php else: ?>
          <p class="muted">No saved versions yet. Your first change creates one.</p>
        <?php endif ?>
      </div>
    </aside>
  </div>

  <dialog class="picker-dialog section-picker" aria-labelledby="sp-title" data-picker>
    <div class="picker-head"><h2 id="sp-title">Add a section</h2><button type="button" class="btn small ghost" data-picker-close>Close</button></div>
    <div class="picker-body">
      <?php foreach ($groups as $group => $types): ?>
        <h3><?= e($group) ?></h3>
        <ul class="section-tiles">
          <?php foreach ($types as $key => $class): ?>
            <li><button type="button" data-add-type="<?= e($key) ?>">
              <svg class="tile-art" viewBox="0 0 120 80" aria-hidden="true" focusable="false"><use href="<?= e(asset('assets/img/sections.svg')) ?>#sec-<?= e($key) ?>"></use></svg>
              <strong><?= e($class::LABEL) ?></strong>
              <span><?= e($class::DESCRIPTION) ?></span>
            </button></li>
          <?php endforeach ?>
        </ul>
      <?php endforeach ?>
      <h3>Saved templates</h3>
      <ul class="template-tiles" data-template-list></ul>
      <p class="muted" data-no-templates>No saved section templates yet. Open a section and choose “Save as template”.</p>
    </div>
  </dialog>
  <div class="sr-only" aria-live="assertive" data-announce></div>
  <?= view('admin/_picker_template') ?>
</div>
<script type="module" src="<?= e(asset('assets/js/builder.js')) ?>"></script>
