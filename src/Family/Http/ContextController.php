<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Application\ListContexts;
use App\Family\Application\SwitchContext;
use App\Identity\Application\SessionState;
use App\Identity\Domain\ReturnTo;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Redirect;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The Trainer context selector page and the switch (AC-24, AC-25, AC-36, AC-37). Route group: Player role (parents and child logins). */
final class ContextController
{
    use ActorAware;

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ListContexts $list,
        private readonly SwitchContext $switch,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $menu = $this->list->execute($this->actor($request), RequestContext::from($request));

        return $this->view->render('family/context.twig', ['page_title' => 'Choose a Trainer', 'menu' => $menu]);
    }

    public function switch(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $state = $request->getAttribute('session_state');
        if (!$state instanceof SessionState) {
            throw new \LogicException('SessionMiddleware is not in the pipeline.');
        }
        $chosen = $this->body($request)['context'] ?? null;
        [$organization, $profile] = array_pad(explode(':', is_string($chosen) ? $chosen : '', 2), 2, '');
        if ($organization === '' || $profile === '') {
            throw new NotFound();
        }
        $this->switch->execute($state, $actor, $organization, $profile);

        $back = ReturnTo::fromReferer($request->getHeaderLine('Referer'), $request->getUri()->getHost());

        return $this->redirect->to($back ?? '/family/events');
    }
}
