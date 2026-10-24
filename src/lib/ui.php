<?php
declare(strict_types=1);

/*
 * Small markup helpers for forms and admin tabs. Every value is escaped
 * here; callers pass plain strings.
 */

/**
 * One labelled field (same markup as the existing admin forms).
 * Types: text, email, url, number, date, datetime-local, time, password, tel,
 * textarea, select, checkbox.
 * @param array{hint?:string, options?:array, required?:bool, rows?:int, max?:int, min?:int, step?:string, placeholder?:string, prefix?:string, class?:string, autocomplete?:string} $o
 */
function field_html(string $name, string $label, string $type, mixed $value, ?string $error = null, array $o = []): string
{
    $id = ($o['prefix'] ?? 'f') . '-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
    $val = is_scalar($value) ? (string) $value : '';
    $hint = (string) ($o['hint'] ?? '');
    $desc = trim(($hint !== '' ? "$id-hint " : '') . ($error ? "$id-err" : ''));
    $attrs = ($desc !== '' ? ' aria-describedby="' . e($desc) . '"' : '') . ($error ? ' aria-invalid="true"' : '')
        . (!empty($o['required']) ? ' required' : '')
        . (isset($o['max']) && in_array($type, ['text', 'email', 'url', 'textarea', 'tel', 'password'], true) ? ' maxlength="' . (int) $o['max'] . '"' : '')
        . (isset($o['max']) && $type === 'number' ? ' max="' . (int) $o['max'] . '"' : '')
        . (isset($o['min']) ? ' min="' . (int) $o['min'] . '"' : '')
        . (isset($o['step']) ? ' step="' . e($o['step']) . '"' : '')
        . (isset($o['placeholder']) ? ' placeholder="' . e($o['placeholder']) . '"' : '')
        . (isset($o['autocomplete']) ? ' autocomplete="' . e($o['autocomplete']) . '"' : '');
    $req = !empty($o['required']) ? ' <span class="req" aria-hidden="true">*</span>' : '';
    $after = ($hint !== '' ? '<p class="hint" id="' . e($id) . '-hint">' . e($hint) . '</p>' : '')
        . ($error ? '<p class="error" id="' . e($id) . '-err">' . e($error) . '</p>' : '');
    $cls = 'field' . (isset($o['class']) ? ' ' . e($o['class']) : '');
    if ($type === 'checkbox') {
        return '<div class="' . $cls . '"><label class="check"><input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ($val === '1' ? ' checked' : '') . $attrs . '> ' . e($label) . '</label>' . $after . '</div>';
    }
    $html = '<div class="' . $cls . '"><label for="' . e($id) . '">' . e($label) . $req . '</label>';
    if ($type === 'textarea') {
        $html .= '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="' . (int) ($o['rows'] ?? 4) . '"' . $attrs . '>' . e($val) . '</textarea>';
    } elseif ($type === 'select') {
        $html .= '<select id="' . e($id) . '" name="' . e($name) . '"' . $attrs . '>';
        foreach ($o['options'] ?? [] as $ov => $ol) {
            $html .= '<option value="' . e((string) $ov) . '"' . ((string) $ov === $val ? ' selected' : '') . '>' . e((string) $ol) . '</option>';
        }
        $html .= '</select>';
    } else {
        $html .= '<input type="' . e($type) . '" id="' . e($id) . '" name="' . e($name) . '"' . ($type !== 'password' ? ' value="' . e($val) . '"' : '') . $attrs . '>';
    }
    return $html . $after . '</div>';
}

/**
 * Tab links for admin queues.
 * @param array<string,array{0:string,1:?int}> $tabs key => [label, count]
 */
function tabs_html(string $base, string $param, string $current, array $tabs, array $keep = []): string
{
    $html = '<nav class="queue-tabs" aria-label="Filter"><ul>';
    foreach ($tabs as $key => [$label, $count]) {
        $href = url_with($base, $keep, [$param => $key === array_key_first($tabs) ? null : $key]);
        $html .= '<li><a href="' . e($href) . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . e($label)
            . ($count !== null ? ' <span class="count tabular">' . (int) $count . '</span>' : '') . '</a></li>';
    }
    return $html . '</ul></nav>';
}

/** Error summary for the top of a form. */
function form_errors_html(array $errors): string
{
    if ($errors === []) {
        return '';
    }
    return '<div class="flash error" role="alert">' . icon('alert') . '<span>Please fix the highlighted fields' . (isset($errors['form']) ? ': ' . e($errors['form']) : '.') . '</span></div>';
}
