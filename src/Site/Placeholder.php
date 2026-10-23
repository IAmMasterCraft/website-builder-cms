<?php
declare(strict_types=1);

namespace Site;

/** Membership routes whose features arrive in phase 4. */
final class Placeholder
{
    public static function join(array $p): void
    {
        render('site/coming_soon', ['title' => 'Join us', 'heading' => 'Membership sign-up is opening soon', 'intro' => 'Online sign-up with email verification and digital member cards is on its way. In the meantime, get in touch and we will add you to our list.', 'noindex' => true]);
    }

    public static function joinGroup(array $p): void
    {
        render('site/coming_soon', ['title' => 'Register a group', 'heading' => 'Group registration is opening soon', 'intro' => 'Affiliated associations will soon be able to register online and appear in our groups directory.', 'noindex' => true]);
    }

    public static function account(array $p): void
    {
        $user = require_login();
        if (is_staff($user)) {
            redirect('/admin');
        }
        render('site/coming_soon', ['title' => 'My account', 'heading' => 'Welcome, ' . $user['display_name'], 'intro' => 'Your member dashboard (profile, ID card, events and more) is being built. You are logged in.', 'noindex' => true]);
    }
}
