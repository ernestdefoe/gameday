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
        return new JsonResponse([
            'board' => $this->current->board(RequestUtil::getActor($request)),
        ]);
    }
}
