<?php

namespace ErnestDefoe\Gameday\Service;

use ErnestDefoe\Gameday\Service\Sports\Sports;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * What an operator has decided.
 *
 * 🚨 Every reader goes through here rather than asking the settings repository
 * for a key. Two callers reading `gameday.lead_minutes` are two chances to
 * disagree about what an empty value means, and the defaults below are the
 * whole answer to that question in one place.
 */
class Settings
{
    public const PREFIX = 'ernestdefoe-gameday.';

    /** Off until somebody says otherwise: installing an extension is not consent to post. */
    public function enabled(): bool
    {
        return (bool) $this->get('enabled', false);
    }

    /**
     * Who the threads are posted as.
     *
     * 🚨 Zero means nobody, and nothing is posted. There is no sensible default
     * here — posting as user 1 would put a board's founder's name on a hundred
     * automated threads they did not write.
     */
    public function authorId(): int
    {
        return max(0, (int) $this->get('author_id', 0));
    }

    /** How long before kickoff a thread opens. */
    public function leadMinutes(): int
    {
        $minutes = (int) $this->get('lead_minutes', 180);

        return $minutes > 0 ? $minutes : 180;
    }

    /** The tag a game goes in when neither team has one. */
    public function fallbackTagId(): int
    {
        return max(0, (int) $this->get('fallback_tag_id', 0));
    }

    /** Whether a finished game gets a recap at all. */
    public function recaps(): bool
    {
        return (bool) $this->get('recaps', true);
    }

    /**
     * Which sport's vocabulary a recap is written in.
     *
     * 🚨 Gridiron by default, because every install that existed before this
     * setting was college football and an upgrade must not change what their
     * recaps say. An unknown value falls back to the same — a settings row
     * naming a sport that has been removed is somebody's install, not a
     * programming error, and a recap in the wrong words beats a scheduled job
     * that dies.
     */
    public function sport(): string
    {
        return (string) $this->get('sport', Sports::DEFAULT);
    }

    /**
     * The timezone a kickoff time is PRINTED in.
     *
     * 🚨 This exists because a post is permanent and a rendered date is not
     * negotiable afterwards. Everything Flarum stores is UTC and there is no
     * forum-wide timezone to consult, so the opening post has to be told which
     * clock the board keeps — otherwise "Kickoff is at 7:30pm" is a wrong
     * number for every reader who is not sitting next to the server.
     *
     * 🚨 UTC is the default rather than the server's own zone, and that is
     * deliberate: a shared host's `date.timezone` is somebody else's decision
     * and changes under you. UTC is at least the same answer everywhere, and
     * the abbreviation is always printed beside the time, so an unconfigured
     * board says something true and slightly inconvenient rather than something
     * confident and wrong.
     *
     * 🚨 An unknown zone falls back rather than throwing. A settings row naming
     * a zone that has been retired is somebody's install, not a programming
     * error, and it must not take the scheduled job that opens threads down
     * with it.
     */
    public function timezone(): string
    {
        $zone = (string) $this->get('timezone', 'UTC');

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC';
    }

    /**
     * Whether a thread is stuck to the top while the game is on.
     *
     * 🚨 Flarum has no "live" state to put a discussion into — that is a
     * Convoro idea and this port does not pretend otherwise. Sticky is the
     * nearest honest equivalent the platform has: it says "this is happening
     * now" in a way readers already understand, and it comes off again when the
     * game ends. Needs flarum/sticky; without it this does nothing and says so
     * on the settings page rather than failing quietly.
     */
    public function stickyWhileLive(): bool
    {
        return (bool) $this->get('sticky_while_live', true);
    }

    /**
     * How the recap is emphasised.
     *
     * 🚨 Decided from what is actually installed, not assumed. A recap written
     * in Markdown on a board with no Markdown extension shows literal `**` to
     * every reader, and one written in BBCode on a board without it shows
     * `[b]`. `none` reads perfectly well and is the right answer more often
     * than either.
     */
    public function emphasis(callable $isEnabled): string
    {
        return match (true) {
            $isEnabled('flarum-bbcode') => Recap::EMPHASIS_BBCODE,
            $isEnabled('flarum-markdown') => Recap::EMPHASIS_MARKDOWN,
            default => Recap::EMPHASIS_NONE,
        };
    }

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    protected function get(string $key, $default)
    {
        $value = $this->settings->get(self::PREFIX . $key);

        return $value === null || $value === '' ? $default : $value;
    }
}
