<?php

declare(strict_types=1);

namespace App\Organization\Http;

use App\Family\Http\ActorAware;
use App\Organization\Application\ListOrganizationPlayers;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Trainer's players and one player's page. The organization is the request context's; a profile of
 * another organization answers 404, exactly like one that does not exist (AC-10).
 */
final class TrainerPlayersController
{
    use ActorAware;

    public function __construct(private readonly View $view, private readonly ListOrganizationPlayers $players) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('trainer/players.twig', [
            'page_title' => 'Players',
            'players' => $this->players->execute($this->actor($request), RequestContext::from($request)),
        ]);
    }

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $player = $this->players->find($this->actor($request), RequestContext::from($request), (string) ($args['id'] ?? ''));

        return $this->view->render('trainer/player.twig', ['page_title' => $player->name, 'player' => $player]);
    }
}
