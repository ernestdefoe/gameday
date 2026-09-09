<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Api\Controller;

use ErnestDefoe\Gameday\GamedayThread;
use ErnestDefoe\Gameday\Service\Scoreboard;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/gameday/board/{id} — what the board says right now.
 *
 * 🚨 This is the whole of "live". The score changes while somebody is reading
 * the page and nothing about a rendered component knows that, so the board asks
 * this every so often and updates itself. Without it a live scoreboard is a
 * photograph of a live scoreboard.
 */
class BoardController implements RequestHandlerInterface
{
    public function __construct(protected Scoreboard $scoreboard)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /*
         * 🚨 The discussion's own visibility decides this, not the fact that a
         * board exists. A game thread can sit in a tag not everybody can read,
         * and an endpoint that answered anyway would publish the existence and
         * the state of a thread its asker cannot open.
         */
        $actor = RequestUtil::getActor($request);
        $id = (int) Arr::get($request->getQueryParams(), 'id', 0);

        $discussion = \Flarum\Discussion\Discussion::query()
            ->whereVisibleTo($actor)
            ->find($id);

        if ($discussion === null) {
            return new JsonResponse(['board' => null], 404);
        }

        $thread = GamedayThread::query()->where('discussion_id', $discussion->id)->first();
        $event = $thread === null ? null : $this->event($thread->event_id);

        return new JsonResponse([
            'board' => $event === null ? null : $this->scoreboard->shape($event, $thread),
        ]);
    }

    /** See DiscussionBoardField for why Picks is reached by name. */
    protected function event(int $id): ?object
    {
        $model = '\\Resofire\\Picks\\PickEvent';

        if (! class_exists($model)) {
            return null;
        }

        return $model::query()->with(['homeTeam', 'awayTeam'])->find($id);
    }
}
