<?php /** @var array $values @var array $errors */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $k): ?string => $errors[$k] ?? null;
?>
<?= field_html('title', 'Job title', 'text', $v('title'), $err('title'), ['max' => 200, 'required' => true, 'prefix' => 'job']) ?>
<div class="form-row">
  <?= field_html('organisation', 'Organisation', 'text', $v('organisation'), $err('organisation'), ['max' => 200, 'required' => true, 'prefix' => 'job']) ?>
  <?= field_html('location', 'Location', 'text', $v('location'), $err('location'), ['max' => 200, 'prefix' => 'job', 'placeholder' => 'Town or area']) ?>
</div>
<div class="form-row">
  <?= field_html('job_type', 'Type', 'select', $v('job_type'), $err('job_type'), ['options' => JOB_TYPES, 'required' => true, 'prefix' => 'job']) ?>
  <?= field_html('salary_text', 'Salary or pay', 'text', $v('salary_text'), $err('salary_text'), ['max' => 120, 'prefix' => 'job', 'placeholder' => 'e.g. £24,000 a year, or Unpaid']) ?>
</div>
<?= field_html('is_remote', 'Remote working possible', 'checkbox', $v('is_remote'), null, ['prefix' => 'job']) ?>
<?= field_html('description', 'Description', 'textarea', $v('description'), $err('description'), ['rows' => 9, 'max' => 10000, 'required' => true, 'prefix' => 'job', 'hint' => 'Plain text: the role, what you are looking for, and any deadlines.']) ?>
<fieldset class="field"><legend>How to apply</legend>
  <div class="form-row">
    <?= field_html('apply_url', 'Application link', 'url', $v('apply_url'), $err('apply_url'), ['max' => 500, 'prefix' => 'job', 'placeholder' => 'https://']) ?>
    <?= field_html('apply_email', 'or email address', 'email', $v('apply_email'), $err('apply_email'), ['max' => 190, 'prefix' => 'job']) ?>
  </div>
</fieldset>
<?= field_html('closing_date', 'Closing date', 'date', $v('closing_date'), $err('closing_date'), ['prefix' => 'job', 'hint' => 'Optional. Posts come down after the closing date, or after ' . JOB_MAX_DAYS . ' days at the latest.']) ?>
