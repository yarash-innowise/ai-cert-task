<?php

declare(strict_types=1);

namespace App\Organization\Http;

use App\Family\Http\ActorAware;
use App\Organization\Application\ViewCoachProfile;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** `GET /coaches/{id}`: a Coach's public profile for the people of their organization. */
final class CoachPublicProfileController
{
    use ActorAware;

    public function __construct(
        private readonly View $view,
        private readonly ViewCoachProfile $viewProfile,
    ) {}

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $coach = $this->viewProfile->execute($this->actor($request), RequestContext::from($request), (string) ($args['id'] ?? ''));

        return $this->view->render('profile/coach-public.twig', ['page_title' => $coach->name, 'coach' => $coach]);
    }
}
