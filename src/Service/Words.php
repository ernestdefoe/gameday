<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service;

use Flarum\Locale\TranslatorInterface;

/**
 * Every word Game Day writes for a reader — posts, cards, blocks — through
 * Flarum's translator.
 *
 * 🚨 Static, because the sports, the recap and the preview are built with `new`
 * and asserted with no container at all: `tests/run.php` hands this a
 * translator of its own, loaded from the same locale file.
 */
final class Words
{
    /** @var (callable(string, array<string, mixed>): string)|null */
    private static $translate = null;

    /** @param (callable(string, array<string, mixed>): string)|null $translate */
    public static function use(?callable $translate): void
    {
        self::$translate = $translate;
    }

    /**
     * @param string               $key    relative to `ernestdefoe-gameday.`
     * @param array<string, mixed> $params
     */
    public static function t(string $key, array $params = []): string
    {
        $id = 'ernestdefoe-gameday.'.$key;

        if (self::$translate !== null) {
            return (self::$translate)($id, $params);
        }

        return resolve(TranslatorInterface::class)->trans($id, $params);
    }

    /**
     * A date with its day and month names in the forum's language.
     *
     * 🚨 `format()` only ever writes English names. Carbon's translated format
     * writes the same thing for English and the reader's words otherwise.
     */
    public static function date(\DateTimeInterface $at, string $format): string
    {
        if (self::$translate !== null || ! class_exists(\Carbon\Carbon::class)) {
            return $at->format($format);
        }

        return \Carbon\Carbon::instance($at)
            ->locale(resolve(TranslatorInterface::class)->getLocale())
            ->translatedFormat($format);
    }
}
