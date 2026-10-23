<?php
declare(strict_types=1);

/*
 * Digital member ID cards (§4.3): one data array drives the HTML card, the
 * print pages (CR80, A4 sheet of 8) and the PNG drawn in the browser.
 */

/** Everything printed on a member's card. Image URLs are same-origin (access-checked). */
function card_data(array $member): array
{
    $logo = media_find((int) setting('site.logo_media_id', 0) ?: null);
    $photo = media_find($member['photo_media_id'] ? (int) $member['photo_media_id'] : null);
    $bg = media_find((int) setting('card.background_media_id', 0) ?: null);
    $sig = media_find((int) setting('card.signature_media_id', 0) ?: null);
    $extra = [];
    if (!empty($member['id'])) {
        $values = custom_values_for_member((int) $member['id']);
        foreach (custom_fields('member') as $f) {
            if ((int) $f['show_on_card'] === 1 && ($values[(int) $f['id']] ?? '') !== '') {
                $extra[] = ['label' => $f['label'], 'value' => custom_value_display($f, $values[(int) $f['id']])];
            }
        }
    }
    $today = (new DateTimeImmutable('now', site_tz()))->format('Y-m-d');
    $expired = $member['card_valid_until'] !== null && (string) $member['card_valid_until'] < $today;
    $site = base_url();
    return [
        'org' => (string) setting('site.name', 'Gather'),
        'initial' => mb_strtoupper(mb_substr((string) setting('site.name', 'G'), 0, 1)),
        'logo' => $logo ? media_url($logo, 400) : null,
        'photo' => $photo ? media_url($photo, 400) : null,
        'background' => $bg ? media_url($bg, 800) : null,
        'signature' => $sig ? media_url($sig, 400) : null,
        'signatory' => (string) member_settings()['signatory'],
        'name' => member_full_name($member),
        'number' => (string) ($member['member_number'] ?? ''),
        'type' => (string) $member['membership_type'],
        'valid_until' => $member['card_valid_until'] ? (new DateTimeImmutable((string) $member['card_valid_until']))->format('M Y') : 'No expiry',
        'valid_until_iso' => (string) ($member['card_valid_until'] ?? ''),
        'expired' => $expired,
        'extra' => $extra,
        'contact' => array_values(array_filter([
            (string) setting('contact.address', ''), (string) setting('contact.phone', ''), (string) setting('contact.email', ''),
            preg_replace('#^https?://#', '', $site),
        ], static fn (string $l): bool => $l !== '')),
        'charity' => (string) setting('org.charity_number', ''),
        'verify_url' => $member['member_number'] && $member['card_code'] ? card_verify_url($member) : '',
    ];
}
