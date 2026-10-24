<?php
declare(strict_types=1);

namespace Admin;

use Core\ConflictException;

/**
 * The page builder (§3.1): editor shell, JSON autosave of the draft,
 * publish, preview (in the editor's iframe), revisions and templates.
 */
final class Builder
{
    private static function page(array $p): array
    {
        $page = q_one('SELECT * FROM pages WHERE id = ?', [(int) $p['id']]);
        if ($page === null) {
            abort(404);
        }
        return $page;
    }

    /** Request body as JSON (autosave and template endpoints). */
    private static function jsonBody(): array
    {
        $raw = (string) file_get_contents('php://input', false, null, 0, 2_000_001);
        if (strlen($raw) > 2_000_000) {
            json_out(['error' => 'The page is too large to save.'], 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            json_out(['error' => 'The request could not be read.'], 400);
        }
        return $data;
    }

    public static function editor(array $p): void
    {
        $user = require_permission('pages_menus');
        $page = self::page($p);
        $sections = builder_draft_sections($page);
        render('admin/builder', [
            'title' => 'Build: ' . $page['title'],
            'page' => $page,
            'config' => self::config($page, $sections),
            'revisions' => builder_revisions($page),
            'unpublished' => builder_has_unpublished($page) || $page['sections'] === null,
            'public_url' => content_public_url('page', $page),
            'body_class' => 'builder-mode',
            'user' => $user,
        ], 'admin');
    }

    /** Everything the editor script needs, embedded as JSON. */
    private static function config(array $page, array $sections): array
    {
        $types = [];
        foreach (builder_section_types() as $key => $class) {
            $schema = array_map([self::class, 'resolveField'], $class::schema());
            $types[$key] = [
                'label' => $class::LABEL, 'description' => $class::DESCRIPTION, 'group' => $class::GROUP,
                'schema' => $schema, 'defaults' => $class::defaults(), 'style' => $class::defaultStyle(),
            ];
        }
        $mediaIds = [];
        array_walk_recursive($sections, static function (mixed $v, int|string $k) use (&$mediaIds): void {
            if (in_array($k, ['media_id', 'bg_media_id', 'video_media_id'], true) && (is_int($v) || (is_string($v) && ctype_digit($v)))) {
                $mediaIds[] = (int) $v;
            }
        });
        media_preload($mediaIds);
        $media = [];
        foreach (array_unique($mediaIds) as $id) {
            $m = media_find($id);
            if ($m !== null) {
                $media[(string) $id] = ['thumb' => $m['kind'] === 'image' ? media_url($m, 400) : null, 'title' => $m['title'] !== '' ? $m['title'] : $m['original_name']];
            }
        }
        $templates = [];
        foreach (q_all("SELECT id, name, sections FROM section_templates WHERE kind = 'section' ORDER BY name") as $t) {
            $templates[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'sections' => builder_decode((string) $t['sections'])];
        }
        return [
            'page' => ['id' => (int) $page['id'], 'title' => $page['title'], 'revision' => (int) $page['revision']],
            'sections' => $sections,
            'types' => $types,
            'styleOptions' => ['background' => BUILDER_BACKGROUNDS, 'spacing' => BUILDER_SPACING, 'width' => BUILDER_WIDTHS],
            'media' => $media,
            'templates' => $templates,
            'urls' => [
                'save' => '/admin/pages/' . $page['id'] . '/builder',
                'preview' => '/admin/pages/' . $page['id'] . '/preview',
                'template' => '/admin/pages/' . $page['id'] . '/templates',
                'picker' => '/admin/media/picker',
                'thumbs' => asset('assets/img/sections.svg'),
            ],
        ];
    }

    /** Resolve option providers so the browser gets plain lists. */
    private static function resolveField(array $f): array
    {
        if ($f['type'] === 'select') {
            $opts = builder_options($f);
            $f['options'] = array_map(static fn ($v, $l): array => ['value' => (string) $v, 'label' => (string) $l], array_keys($opts), $opts);
        }
        if ($f['type'] === 'repeater') {
            $f['fields'] = array_map([self::class, 'resolveField'], $f['fields']);
        }
        return $f;
    }

    /** POST JSON {revision, sections}: validate and autosave the draft. */
    public static function save(array $p): void
    {
        csrf_check();
        $user = require_permission('pages_menus');
        $page = self::page($p);
        $body = self::jsonBody();
        [$sections, $errors] = builder_validate_sections($body['sections'] ?? null);
        if (isset($errors['_page'])) {
            json_out(['error' => $errors['_page']['sections'], 'errors' => $errors], 422);
        }
        try {
            $revision = builder_save_draft((int) $page['id'], $sections, (int) ($body['revision'] ?? -1), (int) $user['id']);
        } catch (ConflictException $e) {
            json_out(['conflict' => true, 'message' => $e->describe() . ' Reload to see their changes.', 'revision' => $e->currentRevision], 409);
        }
        json_out([
            'revision' => $revision,
            'ids' => array_column($sections, 'id'),
            'errors' => (object) $errors,
            'saved_at' => fmt_date(utc_now(), 'H:i'),
        ]);
    }

    public static function publish(array $p): void
    {
        csrf_check();
        $user = require_permission('pages_menus');
        $page = self::page($p);
        try {
            builder_publish((int) $page['id'], input_int('revision', -1), (int) $user['id']);
        } catch (ConflictException $e) {
            flash($e->describe() . ' Nothing was published: check their changes, then publish again.', 'error');
            redirect('/admin/pages/' . $page['id'] . '/builder');
        }
        audit('publish', 'page', (int) $page['id'], (string) $page['title']);
        flash('Published. Visitors now see this version of “' . $page['title'] . '”.');
        redirect('/admin/pages/' . $page['id'] . '/builder');
    }

    public static function restore(array $p): void
    {
        csrf_check();
        $user = require_permission('pages_menus');
        $page = self::page($p);
        try {
            builder_restore((int) $page['id'], (int) $p['rev'], input_int('revision', -1), (int) $user['id']);
        } catch (ConflictException $e) {
            flash($e->describe() . ' Reload, then restore again.', 'error');
            redirect('/admin/pages/' . $page['id'] . '/builder');
        } catch (\DomainException $e) {
            flash($e->getMessage(), 'error');
            redirect('/admin/pages/' . $page['id'] . '/builder');
        }
        audit('restore', 'page', (int) $page['id'], 'revision ' . (int) $p['rev']);
        flash('Revision restored as a draft. Check it in the preview, then Publish to make it live.');
        redirect('/admin/pages/' . $page['id'] . '/builder');
    }

    /** The draft (or one revision) rendered with the site layout, for the editor iframe. */
    public static function preview(array $p): void
    {
        require_permission('pages_menus');
        $page = self::page($p);
        $revId = input_int('revision', 0, true);
        if ($revId > 0) {
            $json = q_val('SELECT sections FROM page_revisions WHERE id = ? AND page_id = ?', [$revId, $page['id']]);
            if ($json === null) {
                abort(404);
            }
            $sections = builder_decode((string) $json);
        } else {
            $sections = builder_draft_sections($page);
        }
        header('Cache-Control: no-store');
        \Site\Pages::renderSections($page, $sections, (int) setting('home.page_id', 0) === (int) $page['id'], query_param('frame') === '1', true);
    }

    /** POST JSON {name, kind: section|page, section_id?, sections?}. */
    public static function saveTemplate(array $p): void
    {
        csrf_check();
        $user = require_permission('pages_menus');
        $page = self::page($p);
        $body = self::jsonBody();
        $kind = (string) ($body['kind'] ?? '');
        $sections = is_array($body['sections'] ?? null) ? $body['sections'] : [];
        try {
            $id = builder_save_template((string) ($body['name'] ?? ''), $kind, $sections, (int) $user['id']);
        } catch (\InvalidArgumentException $e) {
            json_out(['error' => $e->getMessage()], 422);
        }
        audit('create', 'template', $id, (string) ($body['name'] ?? ''));
        $row = q_one('SELECT id, name, sections FROM section_templates WHERE id = ?', [$id]);
        json_out(['id' => $id, 'name' => $row['name'], 'kind' => $kind, 'sections' => builder_decode((string) $row['sections']), 'page' => (int) $page['id']]);
    }

    public static function templates(array $p): void
    {
        require_permission('pages_menus');
        $rows = q_all('SELECT t.*, u.display_name AS author FROM section_templates t LEFT JOIN users u ON u.id = t.created_by ORDER BY t.kind DESC, t.name');
        foreach ($rows as &$r) {
            $r['count'] = count(builder_decode((string) $r['sections']));
            $r['types'] = implode(', ', array_unique(array_map(static fn (array $s): string => (builder_section_class((string) $s['type']) ?? \Sections\Spacer::class)::LABEL, builder_decode((string) $r['sections']))));
        }
        unset($r);
        render('admin/templates', ['title' => 'Templates', 'rows' => $rows, 'starters' => builder_starter_templates()], 'admin');
    }

    public static function deleteTemplate(array $p): void
    {
        csrf_check();
        require_permission('pages_menus');
        $row = q_one('SELECT * FROM section_templates WHERE id = ?', [(int) $p['id']]);
        if ($row === null) {
            abort(404);
        }
        q('DELETE FROM section_templates WHERE id = ?', [$row['id']]);
        audit('delete', 'template', (int) $row['id'], (string) $row['name']);
        flash('Template “' . $row['name'] . '” deleted. Pages built from it are not affected.');
        redirect('/admin/templates');
    }
}
