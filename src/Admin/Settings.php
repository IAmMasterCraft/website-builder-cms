<?php
declare(strict_types=1);

namespace Admin;

use Core\UploadException;

/**
 * Site identity, contact, donations and logo (design, header/footer and
 * membership have their own screens). The full super-admin
 * settings area (membership rules, ID format, SMTP...) arrives in phase 7.
 */
final class Settings
{
    public const SOCIAL = ['facebook' => 'Facebook', 'x' => 'X', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn'];

    public static function edit(array $p): void
    {
        require_permission('super_admin');
        self::form([], []);
    }

    private static function form(array $values, array $errors, int $status = 200): void
    {
        $current = [
            'site_name' => setting('site.name'), 'tagline' => setting('site.tagline'), 'timezone' => setting('site.timezone'),
            'footer_about' => setting('site.footer_about'), 'bank_text' => setting('donate.bank_text', ''),
            'contact_email' => setting('contact.email'), 'contact_phone' => setting('contact.phone'), 'contact_address' => setting('contact.address'),
            'charity_number' => setting('org.charity_number'),
        ];
        foreach (array_values((array) setting('donate.links', [])) as $i => $l) {
            $current['donate_label_' . ($i + 1)] = $l['label'] ?? '';
            $current['donate_url_' . ($i + 1)] = $l['url'] ?? '';
        }
        foreach ((array) setting('social', []) as $k => $v) {
            $current['social_' . $k] = $v;
        }
        $a = (array) setting('analytics', []);
        $current += ['analytics_provider' => (string) ($a['provider'] ?? ''), 'analytics_id' => (string) ($a['site_id'] ?? ''), 'cookie_banner' => (string) ($a['banner'] ?? '')];
        $current += ['help_member_guide' => help_member_guide_access(), 'impersonation_notify' => (bool) setting('impersonation.notify', true) ? '1' : ''];
        render('admin/settings', [
            'title' => 'Site settings', 'values' => $values + $current, 'errors' => $errors,
            'logo' => media_find((int) setting('site.logo_media_id', 0) ?: null),
            'favicon' => media_find((int) setting('site.favicon_media_id', 0) ?: null),
            'icon_tile' => favicon_tile_url(),
        ], 'admin', $status);
    }

    public static function update(array $p): void
    {
        csrf_check();
        $user = require_permission('super_admin');
        $v = [];
        foreach (['site_name', 'tagline', 'timezone', 'footer_about', 'contact_email', 'contact_phone', 'contact_address', 'charity_number', 'bank_text'] as $k) {
            $v[$k] = input($k);
        }
        $errors = [];
        if ($v['site_name'] === '' || mb_strlen($v['site_name']) > 120) {
            $errors['site_name'] = 'Enter the organisation name (up to 120 characters).';
        }
        if (mb_strlen($v['tagline']) > 200) {
            $errors['tagline'] = 'Keep the tagline under 200 characters.';
        }
        if (!in_array($v['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Choose a time zone.';
        }
        if ($v['contact_email'] !== '' && !filter_var($v['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Enter a valid email address.';
        }
        foreach (['bank_text' => 600, 'footer_about' => 600, 'contact_phone' => 40, 'contact_address' => 300, 'charity_number' => 60] as $k => $max) {
            if (mb_strlen($v[$k]) > $max) {
                $errors[$k] = "Keep this under $max characters.";
            }
        }
        $social = [];
        foreach (self::SOCIAL as $k => $label) {
            $url = input('social_' . $k);
            $v['social_' . $k] = $url;
            if ($url !== '' && !preg_match('#^https://[^\s"<>]+$#i', $url)) {
                $errors['social_' . $k] = "$label must be a full https:// link.";
            }
            $social[$k] = $url;
        }
        $donate = [];
        for ($i = 1; $i <= 3; $i++) {
            $label = input('donate_label_' . $i);
            $url = input('donate_url_' . $i);
            $v['donate_label_' . $i] = $label;
            $v['donate_url_' . $i] = $url;
            if ($label === '' && $url === '') {
                continue;
            }
            if ($label === '' || mb_strlen($label) > 40) {
                $errors['donate_label_' . $i] = 'Give the button a short label (up to 40 characters).';
            }
            if (!preg_match('#^https://[^\s"<>]+$#i', $url) || mb_strlen($url) > 500) {
                $errors['donate_url_' . $i] = 'Use a full https:// link to your donation page.';
            }
            $donate[] = ['label' => $label, 'url' => $url];
        }
        $provider = input('analytics_provider');
        $siteId = input('analytics_id');
        $banner = input('cookie_banner');
        $v += ['analytics_provider' => $provider, 'analytics_id' => $siteId, 'cookie_banner' => $banner];
        $v += ['help_member_guide' => input('help_member_guide'), 'impersonation_notify' => input('impersonation_notify')];
        if ($provider !== '') {
            $def = analytics_providers()[$provider] ?? null;
            if ($def === null) {
                $errors['analytics_provider'] = 'Choose a provider from the list.';
            } elseif (!preg_match($def['id_pattern'], $siteId)) {
                $errors['analytics_id'] = $def['id_label'] . ': ' . $def['id_hint'] . '.';
            }
        }
        if (mb_strlen($banner) > 500) {
            $errors['cookie_banner'] = 'Keep the banner text under 500 characters.';
        }
        $logoId = null;
        $file = $_FILES['logo_upload'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $u = media_upload_from_files($file);
                $logoId = media_store($u['tmp'], $u['name'], $u['size'], (int) $user['id'], true, ['kind' => 'image', 'folder' => 'site', 'alt' => $v['site_name']]);
            } catch (UploadException $e) {
                $errors['logo'] = $e->getMessage();
            }
        }
        // C1: site icon, generated into favicon.ico, PNGs and the manifest on the next page view.
        $faviconId = null;
        $file = $_FILES['favicon_upload'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $u = media_upload_from_files($file);
                if (($problem = favicon_upload_problem($u['tmp'], $u['name'], $u['size'])) !== null) {
                    $errors['favicon'] = $problem;
                } elseif (!$errors) {
                    $faviconId = media_store($u['tmp'], $u['name'], $u['size'], (int) $user['id'], true, ['kind' => 'image', 'folder' => 'site', 'alt' => $v['site_name'] . ' icon', 'title' => 'Site icon']);
                }
            } catch (UploadException $e) {
                $errors['favicon'] = $e->getMessage();
            }
        }
        if ($errors) {
            self::form($v, $errors, 422);
            return;
        }
        $iconChange = '';
        if ($faviconId !== null) {
            setting_set('site.favicon_media_id', $faviconId);
            $iconChange = '; site icon uploaded';
        } elseif (input('favicon_remove') === '1' && setting('site.favicon_media_id') !== null) {
            setting_set('site.favicon_media_id', null);
            $iconChange = '; site icon: letter tile';
        }
        setting_set('site.name', $v['site_name']);
        setting_set('site.tagline', $v['tagline']);
        $oldTimezone = (string) setting('site.timezone');
        setting_set('site.timezone', $v['timezone']);
        setting_set('site.footer_about', $v['footer_about']);
        setting_set('donate.bank_text', $v['bank_text']);
        setting_set('donate.links', $donate);
        setting_set('contact.email', $v['contact_email']);
        setting_set('contact.phone', $v['contact_phone']);
        setting_set('contact.address', $v['contact_address']);
        setting_set('org.charity_number', $v['charity_number']);
        setting_set('social', $social);
        setting_set('analytics', ['provider' => $provider, 'site_id' => $provider === '' ? '' : $siteId, 'banner' => $banner]);
        // B2/B3 (only when the form had the panel, so older forms leave these alone)
        $supportChanges = [];
        if (input('support_panel') === '1') {
            $guide = input('help_member_guide') === 'members' ? 'members' : 'public';
            $notify = input('impersonation_notify') === '1';
            if ($guide !== help_member_guide_access()) {
                $supportChanges[] = 'member guide: ' . $guide;
            }
            if ($notify !== (bool) setting('impersonation.notify', true)) {
                $supportChanges[] = 'notice when an admin views an account: ' . ($notify ? 'on' : 'off');
            }
            setting_set('help.member_guide', $guide);
            setting_set('impersonation.notify', $notify);
        }
        if ($logoId !== null) {
            setting_set('site.logo_media_id', $logoId);
        } elseif (input('logo_remove') === '1') {
            setting_set('site.logo_media_id', null);
        }
        audit('update', 'settings', null, 'site settings' . $iconChange . ($provider !== '' ? ' (analytics: ' . $provider . ')' : '') . ($supportChanges ? '; ' . implode('; ', $supportChanges) : ''));
        // Radio repeats keep their local time: a new time zone can make slots clash.
        $clashes = $v['timezone'] !== $oldTimezone ? radio_schedule_conflicts(new \DateTimeZone($v['timezone'])) : [];
        if ($clashes !== []) {
            flash('Settings saved, but with the new time zone some radio slots overlap: ' . implode('; ', array_slice($clashes, 0, 3)) . (count($clashes) > 3 ? ' (and ' . (count($clashes) - 3) . ' more)' : '') . '. Check the radio schedule.', 'error');
            redirect('/admin/settings');
        }
        flash('Settings saved.');
        redirect('/admin/settings');
    }
}
