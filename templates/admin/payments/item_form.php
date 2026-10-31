<?php /** @var ?array $item @var array $values @var array $errors @var array $types @var array $settings */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$locked = $item !== null && $item['status'] !== 'draft';
?>
<div class="pay-admin">
<div class="page-head"><div><p class="crumbs"><a href="/admin/payments/items">Dues and items</a> /</p><h1><?= $item === null ? 'New payment item' : 'Edit ' . e($item['name']) ?></h1></div></div>
<form method="post" action="<?= $item === null ? '/admin/payments/items' : '/admin/payments/items/' . (int) $item['id'] ?>" novalidate>
  <?= csrf_field() ?>
  <?= form_errors_html($errors) ?>
  <div class="form-layout">
    <section class="panel"><div class="panel-body fields">
      <?= field_html('name', 'Name', 'text', $v('name'), $err('name'), ['max' => 120, 'required' => true, 'hint' => 'For example “2027 annual dues”, “Hall building levy”, “Donation”.']) ?>
      <?= field_html('description', 'Description (shown to members)', 'textarea', $v('description'), $err('description'), ['rows' => 3]) ?>
      <?= field_html('item_kind', 'Kind', 'select', $v('item_kind'), $err('item_kind'), ['options' => PAYMENT_ITEM_KINDS]) ?>
      <?php if ($locked): ?>
        <p class="notice-box">This item is <?= e($item['status']) ?>: the amount (<?= e(money($item['amount_minor'] !== null ? (int) $item['amount_minor'] : null)) ?>) and who it applies to (<?= e(payment_item_applies_label($item)) ?>) cannot change any more. Change single members’ charges on their payment page, or close this item and duplicate it.</p>
      <?php else: ?>
        <fieldset class="field"><legend>Amount</legend>
          <label class="check-line"><input type="radio" name="amount_mode" value="fixed"<?= $v('amount_mode') !== 'any' ? ' checked' : '' ?>> <span>A fixed amount</span></label>
          <label class="check-line"><input type="radio" name="amount_mode" value="any"<?= $v('amount_mode') === 'any' ? ' checked' : '' ?>> <span>Any amount (donations; optional items only)</span></label>
        </fieldset>
        <?= field_html('amount', 'Amount (' . site_currency() . ')', 'text', $v('amount'), $err('amount'), ['hint' => 'For example ' . money_input(3000) . '. Leave empty for “any amount”.']) ?>
        <fieldset class="field"<?= $err('applies_to') ? ' aria-describedby="ap-err"' : '' ?>><legend>Applies to</legend>
          <?php foreach (PAYMENT_APPLIES as $k => $l): ?><label class="check-line"><input type="radio" name="applies_to" value="<?= e($k) ?>"<?= $v('applies_to') === $k ? ' checked' : '' ?>> <span><?= e($l) ?></span></label><?php endforeach ?>
          <?php if ($err('applies_to')): ?><p class="error" id="ap-err"><?= e($err('applies_to')) ?></p><?php endif ?>
        </fieldset>
        <?= field_html('membership_type', 'Membership type (when it applies to a type)', 'select', $v('membership_type'), $err('membership_type'), ['options' => ['' => '—'] + array_combine($types, $types)]) ?>
        <fieldset class="fieldset-plain"><legend>Location (when it applies to members in a location)</legend>
          <div class="field"><label for="it-loc-slot">Which location</label><select id="it-loc-slot" name="loc_slot"><?php foreach (geo_filter_slots() as $k => $l): ?><option value="<?= e($k) ?>"<?= $v('loc_slot') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></div>
          <?= view('partials/location_field', ['prefix' => 'loc', 'cfg' => geo_config(['levels' => 3, 'label' => 'Place', 'country_label' => 'Country']), 'values' => ['loc_country' => $v('loc_country'), 'loc_state' => $v('loc_state'), 'loc_area' => $v('loc_area')], 'errors' => [], 'suggest' => null]) ?>
          <?php if ($err('location')): ?><p class="error"><?= e($err('location')) ?></p><?php endif ?>
        </fieldset>
      <?php endif ?>
    </div></section>
    <div class="side">
      <section class="panel"><div class="panel-body fields">
        <?= field_html('due_date', 'Due date (optional)', 'date', $v('due_date'), $err('due_date')) ?>
        <?= field_html('allow_part', 'Allow part payments', 'checkbox', $v('allow_part')) ?>
        <?= field_html('include_new_members', 'Also apply to people who join later', 'checkbox', $v('include_new_members'), null, ['hint' => 'For everyone, a type or a location: new members get a charge when they verify their email.']) ?>
        <?= field_html('counts_as_dues', 'Counts as membership dues', 'checkbox', $v('counts_as_dues'), null, ['hint' => 'When a member has paid it in full, their fee status becomes Paid.']) ?>
        <?= field_html('covers_until', 'Covers until (optional)', 'date', $v('covers_until'), $err('covers_until'), ['hint' => 'Membership dues only: the card “valid until” date moves to this date when paid in full.']) ?>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>Reminders</h2></div><div class="panel-body fields">
        <?= field_html('remind_enabled', 'Email reminders to members with a balance', 'checkbox', $v('remind_enabled'), null, ['hint' => 'Needs a due date. One reminder before and one after.']) ?>
        <?= field_html('remind_before_days', 'Days before the due date', 'number', $v('remind_before_days'), $err('remind_before_days'), ['min' => 0, 'max' => 90, 'hint' => 'Empty = default (' . $settings['remind_before'] . '). 0 = no reminder before.']) ?>
        <?= field_html('remind_after_days', 'Days after the due date', 'number', $v('remind_after_days'), $err('remind_after_days'), ['min' => 0, 'max' => 180, 'hint' => 'Empty = default (' . $settings['remind_after'] . '). 0 = no reminder after.']) ?>
      </div></section>
      <div class="panel panel-body"><button class="btn accent" type="submit"><?= icon('check') ?> Save item</button></div>
    </div>
  </div>
</form>
</div>
