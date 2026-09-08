<?php

namespace ErnestDefoe\Gameday\Api\Controller;

use ErnestDefoe\Gameday\TeamTag;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Resofire\Picks\Team;

/**
 * GET  /api/gameday/team-tags — every team, and the tag its games go in.
 * POST /api/gameday/team-tags — save the mapping.
 *
 * 🚨 The whole list, not a page of it. There are 130-odd FBS teams and an
 * operator mapping them is doing it in one sitting; paging a form somebody is
 * filling in is how half of it gets saved.
 */
class TeamTagsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        if ($request->getMethod() === 'POST') {
            return $this->save($request);
        }

        $mapped = TeamTag::query()->pluck('tag_id', 'team_id');

        $teams = Team::query()
            ->orderBy('name')
            ->get(['id', 'name', 'conference'])
            ->map(fn (Team $team) => [
                'id' => (int) $team->id,
                'name' => (string) $team->name,
                'conference' => (string) $team->conference,
                'tagId' => (int) ($mapped[$team->id] ?? 0),
            ])
            ->values();

        return new JsonResponse(['data' => $teams]);
    }

    protected function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $pairs = (array) ($body['mapping'] ?? []);
        $saved = 0;

        foreach ($pairs as $teamId => $tagId) {
            $teamId = (int) $teamId;
            $tagId = (int) $tagId;

            if ($teamId < 1) {
                continue;
            }

            /*
             * 🚨 Zero means "no tag of its own", and it DELETES rather than
             * storing a zero. A row pointing at tag 0 is a mapping that looks
             * set on every screen that reads it and resolves to nothing.
             */
            if ($tagId < 1) {
                TeamTag::where('team_id', $teamId)->delete();

                continue;
            }

            TeamTag::updateOrCreate(['team_id' => $teamId], ['tag_id' => $tagId]);
            $saved++;
        }

        return new JsonResponse(['saved' => $saved]);
    }
}
