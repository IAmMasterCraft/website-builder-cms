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
        foreach (['regions', 'genders', 'types'] as $k) {
            $values[$k] = implode("\n", $s[$k]);
        }
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
        ], 'admin', $status);
    }

    public static function update(array $p): void
    {
        csrf_check();
        $admin = require_permission('super_admin');
        $v = [];
        foreach (['prefix', 'group_prefix', 'number_format', 'card_months', 'free_first_n', 'terms_url', 'signatory', 'regions', 'genders', 'types'] as $k) {
            $v[$k] = input($k);
        }
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
        $lists = [];
        foreach (['regions' => 'regions', 'genders' => 'options', 'types' => 'membership types'] as $k => $what) {
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
            'newsletter_default' => input('newsletter_default') === '1',
        ] + $lists);
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
