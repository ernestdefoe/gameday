<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Api\Controller;

use ErnestDefoe\Gameday\Service\CurrentGame;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/gameday/board — what is on right now, for a widget.
 *
 * 🚨 A different endpoint from `/gameday/board/{id}`, and deliberately so. That
 * one answers "what does THIS thread's game say"; this one answers "what is on".
 * A widget polling the by-id endpoint would need to know which game it was
 * about before it could ask, and the answer changes at the final whistle —
 * which is exactly the moment a widget should move on to the next one.
 */
class CurrentBoardController implements RequestHandlerInterface
{
    public function __construct(protected CurrentGame $current)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $limit = (int) (\Illuminate\Support\Arr::get($request->getQueryParams(), 'limit', CurrentGame::MOST));

        $boards = $this->current->boards(RequestUtil::getActor($request), $limit);

        return new JsonResponse([
            'boards' => $boards,
            /*
             * The first one again under its old name. A widget narrow enough to
             * draw a single card reads this and does not have to know it was
             * sent nine others — and nothing that already reads `board` breaks.
             */
            'board' => $boards[0] ?? null,
        ]);
    }
}
