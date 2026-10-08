<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Seo;

use ErnestDefoe\Gameday\Service\ScoreboardForDiscussion;
use ErnestDefoe\Gameday\Service\ShareCard;
use Flarum\Foundation\Config;
use FoF\Seo\Page\PageDriverInterface;
use FoF\Seo\SeoProperties;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What a shared game thread looks like outside the site.
 *
 * 🚨 Through fof/seo rather than by appending tags to the document. Setting
 * them directly leaves TWO of each — fof/seo writes the forum-wide ones after a
 * route's own content callable runs, so the specific one loses to the generic
 * one and does it silently. This is the supported seam, and it replaces rather
 * than duplicates.
 *
 * 🚨 Only game threads. Every other discussion keeps the behaviour it had.
 */
class GameThreadPage implements PageDriverInterface
{
    public function __construct(
        protected ScoreboardForDiscussion $boards,
        protected ShareCard $card,
        protected Config $config
    ) {
    }

    public function extensionDependencies(): array
    {
        return [];
    }

    public function handleRoutes(): array
    {
        return ['discussion'];
    }

    public function handle(ServerRequestInterface $request, SeoProperties $seo): void
    {
        $id = (int) preg_replace('/\D.*$/', '', (string) ($request->getQueryParams()['id'] ?? ''));

        if ($id <= 0) {
            return;
        }

        $board = $this->boards->forDiscussion($id);

        if ($board === null) {
            return;
        }

        $home = (array) ($board['home'] ?? []);
        $away = (array) ($board['away'] ?? []);

        $seo->setDescription($this->sentence($board, $home, $away));

        $path = $this->card->pathFor($id, $board);

        if ($path !== null) {
            /*
             * 🚨 An ABSOLUTE url. Open Graph consumers do not resolve a
             * relative one against the page — Facebook, Slack and the rest
             * simply drop the image and fall back to the site-wide default,
             * which is the thing this exists to replace, and they do it
             * without complaining.
             */
            $seo->setImage(rtrim((string) $this->config->url(), '/').$path);
        }
    }

    /**
     * One line that says what happened, or what is about to.
     *
     * @param array<string, mixed> $board
     * @param array<string, mixed> $home
     * @param array<string, mixed> $away
     */
    protected function sentence(array $board, array $home, array $away): string
    {
        $aName = (string) ($away['name'] ?? '');
        $hName = (string) ($home['name'] ?? '');
        $venue = trim((string) ($board['venue'] ?? ''));
        // 🚨 A comma, not another "at". "New Mexico State at Florida State at
        // Doak Campbell Stadium" is what a template gets when the fixture
        // already contains the preposition.
        $where = $venue !== '' ? ', '.$venue : '';

        if (($board['state'] ?? '') === 'final') {
            return sprintf(
                'Final: %s %s, %s %s%s. Scores, box score and the full thread.',
                $aName,
                (string) ($away['score'] ?? ''),
                $hName,
                (string) ($home['score'] ?? ''),
                $where
            );
        }

        $when = trim((string) ($board['statusDetail'] ?? $board['status'] ?? ''));

        return trim(sprintf(
            '%s at %s%s.%s Live score, box score and the game thread.',
            $aName,
            $hName,
            $where,
            $when !== '' ? ' '.$when.'.' : ''
        ));
    }
}
