<?php
declare(strict_types=1);

function site_tz(): DateTimeZone
{
    $name = (string) setting('site.timezone', 'Europe/London');
    try {
        return new DateTimeZone($name);
    } catch (Exception) {
        return new DateTimeZone('UTC');
    }
}

function utc(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('UTC');
}

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Parse a local date-time string (e.g. from datetime-local) in $tz and return UTC "Y-m-d H:i:s". */
function local_to_utc(string $local, ?DateTimeZone $tz = null): ?string
{
    $local = trim($local);
    if ($local === '') {
        return null;
    }
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $local, $tz ?? site_tz());
        if ($dt !== false && $dt->format($format) === $local) {
            return $dt->setTimezone(utc())->format('Y-m-d H:i:s');
        }
    }
    return null;
}

/**
 * Why a local date-time could not be used, for forms: a time that does not
 * exist because the clocks go forward (daylight saving gap) gets its own
 * message. Null when local_to_utc() accepts it or it is empty.
 */
function local_time_problem(string $local, ?DateTimeZone $tz = null): ?string
{
    $local = trim($local);
    if ($local === '' || local_to_utc($local, $tz) !== null) {
        return null;
    }
    $tz ??= site_tz();
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $format) {
        $plain = DateTimeImmutable::createFromFormat('!' . $format, $local, utc());
        if ($plain !== false && $plain->format($format) === $local) {
            return 'There is no ' . $plain->format('H:i') . ' on ' . $plain->format('j M Y') . ' in ' . $tz->getName()
                . ': the clocks go forward then. Choose a time after the change (for example an hour later).';
        }
    }
    return 'Enter a valid date and time.';
}

/** UTC "Y-m-d H:i:s" to a DateTimeImmutable in $tz (site timezone by default). */
function utc_to_local(?string $utc, ?DateTimeZone $tz = null): ?DateTimeImmutable
{
    if ($utc === null || $utc === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, utc());
    return $dt === false ? null : $dt->setTimezone($tz ?? site_tz());
}

/** Value for an <input type="datetime-local">. */
function utc_to_input(?string $utc, ?DateTimeZone $tz = null): string
{
    return utc_to_local($utc, $tz)?->format('Y-m-d\TH:i') ?? '';
}

function fmt_date(?string $utc, string $format = 'j M Y'): string
{
    return utc_to_local($utc)?->format($format) ?? '';
}

function fmt_datetime(?string $utc): string
{
    return fmt_date($utc, 'D j M Y, H:i');
}

/** ISO 8601 for <time datetime>. */
function iso_date(?string $utc): string
{
    return utc_to_local($utc)?->format(DATE_ATOM) ?? '';
}
