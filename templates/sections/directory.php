<?php /** @var array $s @var array $d @var string $h */
$groups = $s['source'] === 'groups'; ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h, 'fallbackUrl' => $groups ? '/groups' : '/branches']) ?>
  <?php if (!$d['rows']): ?>
    <?= view('partials/empty', ['message' => $groups ? 'No groups listed yet.' : 'No branches listed yet.']) ?>
  <?php elseif ($s['layout'] === 'list'): ?>
    <ul class="people-list">
      <?php foreach ($d['rows'] as $r): ?><li><a href="/<?= $groups ? 'groups' : 'branches' ?>#<?= e($r['slug']) ?>"><strong><?= e($r['name']) ?></strong></a><span class="muted"><?= e($groups ? (string) $r['country'] : (string) $r['region']) ?></span></li><?php endforeach ?>
    </ul>
  <?php else: ?>
    <ul class="dir-list">
      <?php foreach ($d['rows'] as $r): $logo = $groups ? media_find($r['logo_media_id'] ? (int) $r['logo_media_id'] : null) : null; ?>
        <li class="dir-item">
          <?php if ($logo): ?><?= img_html($logo, '56px', '', 'dir-logo', true, 400) ?><?php endif ?>
          <h3><?= e($r['name']) ?></h3>
          <?php if (!$groups && $r['region'] !== ''): ?><span class="badge"><?= icon('pin') ?> <?= e($r['region']) ?></span><?php endif ?>
          <?php if ($groups && $r['group_number']): ?><span class="badge brand tabular"><?= e($r['group_number']) ?></span><?php endif ?>
          <dl>
            <?php if ($r['contact_person'] !== ''): ?><dt>Contact</dt><dd><?= e($r['contact_person']) ?></dd><?php endif ?>
            <?php if ($r['email']): ?><dt>Email</dt><dd><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></dd><?php endif ?>
            <?php if ($r['phone']): ?><dt>Phone</dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $r['phone'])) ?>"><?= e($r['phone']) ?></a></dd><?php endif ?>
            <?php if (!$groups && $r['meeting_venue']): ?><dt>Meets</dt><dd><?= e($r['meeting_venue']) ?></dd><?php endif ?>
          </dl>
        </li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
</div>
