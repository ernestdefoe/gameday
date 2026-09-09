<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Api\Controller;

use ErnestDefoe\Gameday\GamedayThread;
use ErnestDefoe\Gameday\Service\LiveReactions;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET  /api/gameday/reactions/{id}?since=… — what has been thrown lately.
 * POST /api/gameday/reactions/{id}        — throw one.
 */
class ReactionsController implements RequestHandlerInterface
{
    public function __construct(protected LiveReactions $reactions)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $id = (int) Arr::get($request->getQueryParams(), 'id', 0);

        /*
         * 🚨 The discussion's own visibility, as everywhere else here. A game
         * thread can live in a tag not everybody reads, and an endpoint that
         * answered anyway would broadcast the fact that a room is roaring to
         * somebody who cannot see the room.
         */
        $discussion = Discussion::query()->whereVisibleTo($actor)->find($id);

        if ($discussion === null) {
            return new JsonResponse(['reactions' => [], 'now' => microtime(true)], 404);
        }

        if ($request->getMethod() === 'POST') {
            $actor->assertRegistered();

            /*
             * 🚨 Only while the game is LIVE. This is the whole idea — a roar
             * belongs to the moment, and a thread that keeps accepting them
             * afterwards is just a slower, worse reaction button beside the
             * real one.
             */
            $thread = GamedayThread::query()->where('discussion_id', $discussion->id)->first();

            if (($thread->state ?? null) !== GamedayThread::LIVE) {
                return new JsonResponse(['accepted' => false, 'reason' => 'not_live'], 409);
            }

            $body = (array) $request->getParsedBody();
            $ok = $this->reactions->push($discussion->id, (int) $actor->id, (string) ($body['emoji'] ?? ''));

            return new JsonResponse(['accepted' => $ok]);
        }

        $since = (float) Arr::get($request->getQueryParams(), 'since', 0);

        return new JsonResponse($this->reactions->since($discussion->id, $since));
    }
}
