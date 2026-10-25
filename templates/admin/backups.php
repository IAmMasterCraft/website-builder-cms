<?php /** @var array $settings @var array $errors @var array $stored @var array $uploads @var int $db_size @var bool $cron_ok @var bool $gzip */ ?>
<div class="page-head"><div><h1>Backups</h1><p>Download a copy of the database and the uploaded files, or let the server keep daily copies. Backups contain members’ personal data: store them safely.</p></div></div>
<div class="form-layout">
  <div>
    <section class="panel"><div class="panel-head"><h2>Download now</h2></div>
      <div class="panel-body">
        <div class="backup-actions">
          <form method="post" action="/admin/backups/database">
            <?= csrf_field() ?>
            <h3>Database</h3>
            <p class="muted">All content, members, settings and the audit log as SQL<?= $gzip ? ' (gzip)' : '' ?>. About <?= e(human_bytes($db_size)) ?> before compression.</p>
            <button class="btn accent" type="submit"><?= icon('download') ?> Download database</button>
          </form>
          <form method="post" action="/admin/backups/uploads">
            <?= csrf_field() ?>
            <h3>Uploaded files</h3>
            <p class="muted"><?= e(pluralise($uploads['files'], 'file')) ?>, <?= e(human_bytes($uploads['bytes'])) ?>: images, documents, radio media and member photos (ZIP).</p>
            <button class="btn ghost" type="submit"><?= icon('download') ?> Download uploads</button>
          </form>
        </div>
        <p class="hint">To restore, upload the files to a fresh copy of the site and run <code>php bin/restore gather-…-db.sql.gz</code>, then unzip the uploads into the site folder. The guide <em>docs/manager-guide/backups.md</em> walks through it.</p>
      </div>
    </section>
    <section class="panel"><div class="panel-head"><h2>Stored on the server</h2><span class="muted small-text">storage/backups (not reachable from the web)</span></div>
      <div class="table-wrap"><table class="data">
        <thead><tr><th scope="col">File</th><th scope="col">Size</th><th scope="col">Made</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
        <?php if (!$stored): ?><tr><td colspan="4" class="empty-row">No stored backups yet.</td></tr><?php endif ?>
        <?php foreach ($stored as $b): ?>
          <tr><td><code><?= e($b['name']) ?></code> <span class="badge"><?= $b['manual'] ? 'manual' : 'daily' ?></span></td><td class="tabular"><?= e(human_bytes($b['size'])) ?></td><td class="tabular"><?= e(fmt_datetime(gmdate('Y-m-d H:i:s', $b['time']))) ?></td>
            <td class="actions-cell"><a class="btn tiny ghost" href="/admin/backups/files/<?= e($b['name']) ?>">Download<span class="sr-only"> <?= e($b['name']) ?></span></a>
              <form class="inline-form" method="post" action="/admin/backups/files/<?= e($b['name']) ?>/delete" data-confirm="Delete <?= e($b['name']) ?>?"><?= csrf_field() ?><button class="btn tiny danger-ghost" type="submit">Delete<span class="sr-only"> <?= e($b['name']) ?></span></button></form></td></tr>
        <?php endforeach ?>
        </tbody></table></div>
      <form class="panel-body btn-row" method="post" action="/admin/backups/run">
        <?= csrf_field() ?>
        <label class="check"><input type="checkbox" name="uploads" value="1"> Include uploaded files</label>
        <button class="btn ghost small" type="submit"><?= icon('check') ?> Back up to the server now</button>
        <span class="hint muted small-text">Manual backups are kept separately from the daily ones (the newest <?= (int) $settings['keep'] ?> of each).</span>
      </form>
    </section>
  </div>
  <form class="panel side" method="post" action="/admin/backups/settings" novalidate>
    <?= csrf_field() ?>
    <div class="panel-head"><h2>Daily backup</h2></div>
    <div class="panel-body fields">
      <?php if (!$cron_ok): ?><p class="notice-box">Cron is not running, so scheduled backups will not happen. See <a href="/admin/health">System health</a>.</p><?php endif ?>
      <?= field_html('enabled', 'Make a backup every day', 'checkbox', !empty($settings['enabled']) ? '1' : '', null, ['prefix' => 'b']) ?>
      <?= field_html('time', 'Time (site time zone)', 'time', (string) $settings['time'], $errors['time'] ?? null, ['prefix' => 'b']) ?>
      <?= field_html('keep', 'Keep the newest', 'number', (string) $settings['keep'], $errors['keep'] ?? null, ['prefix' => 'b', 'min' => 1, 'max' => 60, 'hint' => 'Older backups are deleted automatically (counted separately for database and uploads).']) ?>
      <?= field_html('uploads', 'Include uploaded files (needs disk space for a full copy each day)', 'checkbox', !empty($settings['uploads']) ? '1' : '', null, ['prefix' => 'b']) ?>
      <p class="hint">Keep a copy off the server too: download one now and then, or use your hosting’s own backup tool (cPanel Backup, Plesk Backup Manager, a VPS snapshot).</p>
      <button class="btn accent" type="submit"><?= icon('check') ?> Save schedule</button>
    </div>
  </form>
</div>
