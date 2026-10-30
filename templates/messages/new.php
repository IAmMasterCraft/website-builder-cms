<?php
/** @var array $ctx @var string $q @var bool $searched @var array $results @var array $selected @var bool $team_option @var bool $team_checked
 *  @var array $values @var array $errors @var bool $new_account @var bool $member_to_member */
$base = $ctx['base'];
$person = static function (array $r, bool $checked): string {
    return '<label class="check-line"><input type="checkbox" name="to[]" value="' . (int) $r['id'] . '"' . ($checked ? ' checked' : '') . '> <span>' . e($r['name'])
        . ($r['number'] !== '' ? ' <span class="muted">' . e($r['number']) . '</span>' : '') . ($r['kind'] === 'staff' ? ' <span class="dm-role">Admin</span>' : ($r['kind'] === 'group' ? ' <span class="muted">(group)</span>' : '')) . '</span></label>';
};
?>
<?php if ($ctx['admin']): ?><div class="page-head"><div><p class="crumbs"><a href="/admin/messages">Messages</a></p><h1>New message</h1></div></div>
<?php else: ?><p class="dm-back"><a href="<?= e($base) ?>"><?= icon('chevron-left') ?> All messages</a></p><?php endif ?>
<form class="dm dm-new<?= $ctx['admin'] ? ' panel' : ' dash-card' ?>" method="post" action="<?= e($base) ?>" enctype="multipart/form-data" data-dm-new data-recipients="<?= e($base . '/recipients') ?>">
  <?= csrf_field() ?>
  <?php if (isset($errors['form'])): ?><p class="error" role="alert"><?= e($errors['form']) ?></p><?php endif ?>
  <fieldset class="dm-to"><legend>To</legend>
    <?php if ($team_option): ?>
      <label class="check-line dm-team-option"><input type="checkbox" name="team" value="1" data-dm-team<?= $team_checked ? ' checked' : '' ?>> <span><strong>The team</strong> (<?= e((string) setting('site.name')) ?>)<br><span class="muted">Questions for the organisation. Any admin who looks after messages can answer.</span></span></label>
    <?php endif ?>
    <div class="dm-selected" data-dm-selected-box<?= $selected === [] ? ' hidden' : '' ?>>
      <p class="dm-sub">Chosen</p>
      <div data-dm-selected><?php foreach ($selected as $r): ?><?= $person($r, true) ?><?php endforeach ?></div>
    </div>
    <div class="field">
      <label for="dm-q">Find people by name or member number</label>
      <div class="dm-search-row"><input type="search" id="dm-q" name="q" value="<?= e($q) ?>" maxlength="80" autocomplete="off" data-dm-q aria-describedby="dm-q-hint">
        <button class="btn ghost small" type="submit" name="_find" value="1" formnovalidate data-dm-find><?= icon('search') ?> Search</button></div>
      <p class="hint" id="dm-q-hint"><?php if ($ctx['admin']): ?>You can message any member, group or admin.<?php elseif ($new_account): ?>During your first day as a member you can message the team only.<?php elseif (!$member_to_member): ?>Messages between members are switched off; you can message the team.<?php else: ?>Type at least <?= MSG_SEARCH_MIN ?> letters of a name, or a full member number. Members who chose not to be found do not appear.<?php endif ?></p>
    </div>
    <div class="dm-results" data-dm-results>
      <?php if ($results !== []): ?><p class="dm-sub"><?= $q === '' ? 'Admins you can message' : 'Results' ?></p><?php foreach ($results as $r): ?><?= $person($r, false) ?><?php endforeach ?>
      <?php elseif (!empty($search_limited)): ?><p class="muted">Too many searches. Please wait a few minutes.</p>
      <?php elseif ($searched && $q !== ''): ?><p class="muted"><?= !empty($short_term) ? 'Type at least ' . MSG_SEARCH_MIN . ' letters of a name, or a full member number.' : 'Nobody found that you can message.' ?></p><?php endif ?>
    </div>
    <p class="sr-only" role="status" data-dm-results-status></p>
  </fieldset>
  <div class="field" data-dm-title-field>
    <label for="dm-title">Group name</label>
    <input type="text" id="dm-title" name="title" maxlength="<?= MSG_TITLE_MAX ?>" value="<?= e((string) ($values['title'] ?? '')) ?>" aria-describedby="dm-title-hint">
    <p class="hint" id="dm-title-hint">Needed when you message more than one person (up to <?= MSG_GROUP_MAX ?> people including you).</p>
  </div>
  <?php if ($ctx['admin']): ?>
    <fieldset class="dm-announce"><legend>Announcement group</legend>
      <label class="check-line"><input type="checkbox" name="announcement" value="1"<?= !empty($values['announcement']) ? ' checked' : '' ?>> <span><strong>Only admins can post</strong><br><span class="muted">For larger groups (up to <?= number_format(MSG_ANNOUNCEMENT_MAX) ?> people). Members read and can leave.</span></span></label>
      <label class="check-line"><input type="checkbox" name="everyone" value="1"<?= !empty($values['everyone']) ? ' checked' : '' ?>> <span><strong>Add every active member and group</strong><br><span class="muted">Only with “Only admins can post”. People who blocked you are left out.</span></span></label>
    </fieldset>
  <?php endif ?>
  <div class="field">
    <label for="dm-body">Message</label>
    <textarea id="dm-body" name="body" rows="5" maxlength="<?= MSG_MAX ?>"><?= e((string) ($values['body'] ?? '')) ?></textarea>
    <p class="hint">Plain text, up to <?= number_format(MSG_MAX) ?> characters; web addresses become links.</p>
  </div>
  <div class="field"><label for="dm-file">Attach a photo or PDF (optional, up to <?= MSG_ATTACH_MAX_BYTES / 1048576 ?> MB)</label><input type="file" id="dm-file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf"></div>
  <p><button class="btn accent" type="submit"><?= icon('send') ?> Send</button></p>
</form>
