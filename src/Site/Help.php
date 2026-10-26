<?php
declare(strict_types=1);

namespace Site;

/** /help: the member guide in the site theme (public or members-only per setting; B3). */
final class Help
{
    private static function allowed(): void
    {
        if (help_member_guide_access() === 'public') {
            return;
        }
        $user = require_login();
        if (!is_staff($user) && !viewer_is_member($user)) {
            abort(403, 'The member guide is for members. Please verify your email address first.');
        }
    }

    public static function show(array $p): void
    {
        self::allowed();
        $page = help_page('member-guide', 'public');
        $q = mb_substr(query_param('q'), 0, 100);
        render('site/help', [
            'title' => $page['title'], 'page' => $page, 'q' => $q, 'results' => $q !== '' ? help_search($q, 'public') : null,
            'meta_description' => 'How to join, verify your email, use your member card and manage your account.',
            'canonical' => '/help', 'noindex' => help_member_guide_access() !== 'public',
        ]);
    }

    public static function image(array $p): void
    {
        self::allowed();
        \Admin\Help::sendImage((string) $p['name'], help_member_guide_access() === 'public');
    }
}
