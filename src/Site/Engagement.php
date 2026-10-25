<?php
declare(strict_types=1);

namespace Site;

/** Likes and comments (§4.7). Every action works as a normal form post; community.js sends likes as JSON. */
final class Engagement
{
    private static function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** Back to the item (only same-site paths). */
    private static function back(string $anchor = ''): never
    {
        redirect(safe_redirect_target(input('return'), '/') . $anchor);
    }

    private static function member(): array
    {
        $user = current_user();
        if ($user === null) {
            if (self::wantsJson()) {
                json_out(['error' => 'Log in to take part.', 'login' => '/login'], 401);
            }
            redirect('/login?next=' . rawurlencode(safe_redirect_target(input('return'), '/')));
        }
        if (!viewer_is_member($user)) {
            self::wantsJson() ? json_out(['error' => 'Please verify your email first.'], 403) : abort(403, 'Please verify your email first.');
        }
        return $user;
    }

    public static function like(array $p): void
    {
        csrf_check();
        $user = self::member();
        $type = input('item_type');
        $id = input_int('item_id');
        if (engagement_item($type, $id, 'likes', $user) === null) {
            self::wantsJson() ? json_out(['error' => 'Likes are not available here.'], 404) : abort(404);
        }
        if (rate_limited('like:' . $user['id'], 120, 600)) {
            self::wantsJson() ? json_out(['error' => 'Slow down a little.'], 429) : abort(429);
        }
        rate_hit('like:' . $user['id']);
        [$liked, $count] = like_toggle((int) $user['id'], $type, $id);
        if (self::wantsJson()) {
            json_out(['liked' => $liked, 'count' => $count]);
        }
        self::back('#like-' . $type . '-' . $id);
    }

    public static function comment(array $p): void
    {
        csrf_check();
        $user = self::member();
        $parent = input_int('parent_id');
        [$id, $error] = comment_add($user, input('item_type'), input_int('item_id'), is_string($_POST['body'] ?? null) ? $_POST['body'] : '', $parent > 0 ? $parent : null);
        if ($id === null) {
            $_SESSION['comment_error'] = ['parent' => $parent, 'message' => $error, 'body' => mb_substr(is_string($_POST['body'] ?? null) ? $_POST['body'] : '', 0, COMMENT_MAX)];
            self::back('#comments');
        }
        flash('Your comment is published.');
        self::back('#comment-' . $id);
    }

    public static function edit(array $p): void
    {
        csrf_check();
        $user = self::member();
        $error = comment_edit((int) $p['id'], $user, is_string($_POST['body'] ?? null) ? $_POST['body'] : '');
        if ($error !== null) {
            flash($error, 'error');
        } else {
            flash('Comment updated.');
        }
        self::back('#comment-' . (int) $p['id']);
    }

    public static function delete(array $p): void
    {
        csrf_check();
        $user = self::member();
        flash(comment_delete_own((int) $p['id'], $user) ? 'Your comment was deleted.' : 'That comment could not be deleted.');
        self::back('#comments');
    }

    public static function report(array $p): void
    {
        csrf_check();
        $user = self::member();
        $ok = comment_report((int) $p['id'], (int) $user['id'], input('reason'));
        flash($ok ? 'Thank you. A moderator will look at this comment.' : 'You have already reported this comment, or reached today’s limit of ' . COMMENT_REPORTS_PER_DAY . ' reports.', 'info');
        self::back('#comment-' . (int) $p['id']);
    }
}
