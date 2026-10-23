<?php
declare(strict_types=1);

namespace Core;

/** Someone else saved first (optimistic lock on pages.revision, §12). */
final class ConflictException extends \RuntimeException
{
    public function __construct(public readonly string $by, public readonly string $atUtc, public readonly int $currentRevision)
    {
        parent::__construct('Edited by ' . $by);
    }

    /** "edited by X at hh:mm" in the site timezone. */
    public function describe(): string
    {
        return 'This page was edited by ' . $this->by . ' at ' . fmt_date($this->atUtc, 'H:i') . ' while you were working.';
    }
}
