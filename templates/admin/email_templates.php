<?php /** @var array $groups group => list of templates */ ?>
<div class="page-head"><div><h1>Email templates</h1><p>The automatic emails the site sends. Edit the wording; placeholders like <code>{first_name}</code> are filled in for each person.</p></div></div>
<?php foreach ($groups as $group => $list): ?>
  <section class="panel">
    <div class="panel-head"><h2><?= e($group) ?></h2></div>
    <ul class="row-list">
      <?php foreach ($list as $t): ?>
        <li>
          <div><a class="row-title" href="/admin/email-templates/<?= e($t['key']) ?>"><?= e($t['label']) ?></a><p class="muted small-text"><?= e($t['description']) ?></p></div>
          <div class="row-meta"><?php if ($t['customised']): ?><span class="badge ok">Edited</span><?php else: ?><span class="badge">Default</span><?php endif ?>
            <a class="btn tiny ghost" href="/admin/email-templates/<?= e($t['key']) ?>">Edit<span class="sr-only"> <?= e($t['label']) ?></span></a></div>
        </li>
      <?php endforeach ?>
    </ul>
  </section>
<?php endforeach ?>
