<?php /** @var array $groups @var array $people */ ?>
<?= view('partials/page_hero', ['heading' => 'Our leaders', 'intro' => 'The volunteers who guide the organisation, elected by members every year.', 'crumbs' => ['Leaders' => null]]) ?>
<?php if ($groups): foreach ($groups as $i => $g): $list = $people[(int) $g['id']] ?? []; ?>
<section class="section<?= $i % 2 === 1 ? ' soft' : '' ?>" aria-labelledby="group-<?= (int) $g['id'] ?>">
  <div class="container">
    <div class="section-head" data-reveal>
      <div><span class="eyebrow"><?= e(pluralise((int) $g['n'], 'person', 'people')) ?></span><h2 id="group-<?= (int) $g['id'] ?>"><?= e($g['name']) ?></h2><?php if (!empty($g['description'])): ?><p><?= e($g['description']) ?></p><?php endif ?></div>
      <a class="link-arrow" href="/leaders/<?= e($g['slug']) ?>">View <?= e(mb_strtolower($g['name'])) ?> <?= icon('arrow-right') ?></a>
    </div>
    <?php if ($list): ?>
      <div class="grid cols-4 people" data-reveal-group><?php foreach ($list as $l): ?><?= view('partials/person_card', ['leader' => $l, 'groupSlug' => $g['slug']]) ?><?php endforeach ?></div>
    <?php else: ?><?= view('partials/empty', ['message' => 'No current members of this group are listed.']) ?><?php endif ?>
  </div>
</section>
<?php endforeach; else: ?>
<section class="section"><div class="container"><?= view('partials/empty') ?></div></section>
<?php endif ?>
