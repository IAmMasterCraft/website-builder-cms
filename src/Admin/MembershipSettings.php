<?php
declare(strict_types=1);

namespace Admin;

use Core\UploadException;

/** Super admin: membership rules, member number format, ID card assets. */
final class MembershipSettings
{
    public static function edit(array $p): void
    {
        require_permission('super_admin');
        $s = member_settings();
        $values = $s;
        foreach (['genders', 'types'] as $k) {
            $values[$k] = implode("\n", $s[$k]);
        }
        foreach (geo_origin_settings() as $k => $v) {
            $values['origin_' . $k] = is_bool($v) ? ($v ? '1' : '') : (string) $v;
        }
        $values['card_location_1'] = $s['card_locations'][0] ?? '';
        $values['card_location_2'] = $s['card_locations'][1] ?? '';
        self::form($values, []);
    }

    private static function form(array $values, array $errors, int $status = 200): void
    {
        $s = member_settings();
        render('admin/membership_settings', [
            'title' => 'Membership settings', 'values' => $values, 'errors' => $errors,
            'example' => member_number_build(member_number_format_problem((string) $values['number_format']) === null ? (string) $values['number_format'] : $s['number_format'], preg_match('/^[A-Z0-9]{1,8}$/', (string) $values['prefix']) ? (string) $values['prefix'] : $s['prefix'], (int) date('Y'), 42),
            'background' => media_find((int) setting('card.background_media_id', 0) ?: null),
            'signature' => media_find((int) setting('card.signature_media_id', 0) ?: null),
            'card_location_options' => card_location_options() + self::originCardOptions(),
        ], 'admin', $status);
    }

    /** Origin lines are offered even while the origin block is off, so they can be chosen together. */
    private static function originCardOptions(): array
    {
        return ['origin_country' => ['Country of origin'], 'origin_admin1' => ['State of origin'], 'origin_admin2' => ['Local area of origin']];
    }

    public static function update(array $p): void
    {
        csrf_check();
        $admin = require_permission('super_admin');
        $v = [];
        foreach (['prefix', 'group_prefix', 'number_format', 'card_months', 'free_first_n', 'terms_url', 'signatory', 'genders', 'types',
            'origin_legend', 'origin_levels', 'origin_required', 'origin_country', 'origin_country_label', 'origin_l1_label', 'origin_l2_label', 'card_location_1', 'card_location_2'] as $k) {
            $v[$k] = input($k);
        }
        $v['origin_enabled'] = input('origin_enabled') === '1' ? '1' : '';
        $v['newsletter_default'] = input('newsletter_default') === '1';
        $v['prefix'] = strtoupper($v['prefix']);
        $v['group_prefix'] = strtoupper($v['group_prefix']);
        $errors = [];
        foreach (['prefix', 'group_prefix'] as $k) {
            if (($e = member_prefix_problem($v[$k])) !== null) {
                $errors[$k] = $e;
            }
        }
        if ($v['prefix'] === $v['group_prefix']) {
            $errors['group_prefix'] = 'Use a different prefix for groups so the numbers never clash.';
        }
        if (($e = member_number_format_problem($v['number_format'])) !== null) {
            $errors['number_format'] = $e;
        } elseif (!str_contains($v['number_format'], '{PREFIX}')) {
            $errors['number_format'] = 'Include {PREFIX} so member and group numbers stay apart.';
        }
        if (!ctype_digit($v['card_months']) || (int) $v['card_months'] < 1 || (int) $v['card_months'] > 120) {
            $errors['card_months'] = 'Enter a number of months between 1 and 120.';
        }
        if (!ctype_digit($v['free_first_n']) || (int) $v['free_first_n'] > 100000) {
            $errors['free_first_n'] = 'Enter 0 (off) or a number of members.';
        }
        if (safe_url($v['terms_url']) === '') {
            $errors['terms_url'] = 'Enter a page address such as /privacy-policy or a full https:// link.';
        }
        if ($v['signatory'] === '' || mb_strlen($v['signatory']) > 60) {
            $errors['signatory'] = 'Enter the signatory’s title (up to 60 characters).';
        }
        // C4: the "origin" block on the sign-up form (Country -> State -> Local area).
        $origin = [
            'enabled' => $v['origin_enabled'] === '1', 'levels' => (int) $v['origin_levels'], 'required' => (int) $v['origin_required'],
            'country' => strtoupper($v['origin_country']), 'country_label' => $v['origin_country_label'], 'l1_label' => $v['origin_l1_label'],
            'l2_label' => $v['origin_l2_label'], 'legend' => $v['origin_legend'],
        ];
        if ($origin['levels'] < 1 || $origin['levels'] > 3) {
            $errors['origin_levels'] = 'Choose how many levels to ask.';
        }
        if ($origin['required'] < 0 || $origin['required'] > max(1, $origin['levels'])) {
            $errors['origin_required'] = 'Choose which levels are required (at most the levels you ask).';
        }
        if ($origin['country'] !== '' && !geo_country_exists($origin['country'])) {
            $errors['origin_country'] = 'Choose a country from the list, or “Any country”.';
        }
        if ($origin['country'] !== '' && $origin['levels'] < 2) {
            $errors['origin_levels'] = 'With a fixed country, ask at least the first level (for example the state).';
        }
        foreach (['legend' => 80, 'country_label' => 60, 'l1_label' => 60, 'l2_label' => 60] as $k => $max) {
            if (mb_strlen($origin[$k]) > $max || preg_match('/[<>]/', $origin[$k])) {
                $errors['origin_' . $k] = "Keep this under $max characters.";
            }
        }
        $origin['legend'] = $origin['legend'] !== '' ? $origin['legend'] : 'Where you are from';
        $origin['country_label'] = $origin['country_label'] !== '' ? $origin['country_label'] : 'Country of origin';
        $cardLocations = [];
        $allowedCard = card_location_options() + self::originCardOptions();
        foreach (['card_location_1', 'card_location_2'] as $k) {
            if ($v[$k] === '') {
                continue;
            }
            if (!isset($allowedCard[$v[$k]])) {
                $errors[$k] = 'Choose one of the options.';
            } elseif (!in_array($v[$k], $cardLocations, true)) {
                $cardLocations[] = $v[$k];
            }
        }
        $lists = [];
        foreach (['genders' => 'options', 'types' => 'membership types'] as $k => $what) {
            $lists[$k] = lines_to_list($v[$k]);
            if ($k === 'types' && $lists[$k] === []) {
                $errors[$k] = 'Give at least one membership type.';
            }
        }
        $uploads = [];
        foreach (['background' => 'card.background_media_id', 'signature' => 'card.signature_media_id'] as $k => $settingKey) {
            $file = $_FILES[$k] ?? null;
            if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $u = media_upload_from_files($file);
                    $uploads[$settingKey] = media_store($u['tmp'], $u['name'], $u['size'], (int) $admin['id'], true, ['kind' => 'image', 'visibility' => 'members', 'folder' => 'id-cards', 'title' => 'Card ' . $k]);
                } catch (UploadException $e) {
                    $errors[$k] = $e->getMessage();
                }
            }
        }
        if ($errors) {
            self::form($v, $errors, 422);
            return;
        }
        setting_set('members', [
            'prefix' => $v['prefix'], 'group_prefix' => $v['group_prefix'], 'number_format' => $v['number_format'],
            'card_months' => (int) $v['card_months'], 'free_first_n' => (int) $v['free_first_n'], 'terms_url' => $v['terms_url'], 'signatory' => $v['signatory'],
            'newsletter_default' => input('newsletter_default') === '1', 'card_locations' => $cardLocations,
        ] + $lists);
        setting_set('members.origin', $origin);
        foreach ($uploads as $key => $id) {
            setting_set($key, $id);
        }
        foreach (['background' => 'card.background_media_id', 'signature' => 'card.signature_media_id'] as $k => $key) {
            if (!isset($uploads[$key]) && input($k . '_remove') === '1') {
                setting_set($key, null);
            }
        }
        audit('update', 'settings', null, 'membership settings');
        flash('Membership settings saved. New numbers use the format ' . member_number_build($v['number_format'], $v['prefix'], (int) date('Y'), 1) . '; numbers already issued do not change.');
        redirect('/admin/settings/membership');
    }
}
