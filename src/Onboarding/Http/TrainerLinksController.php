<?php

declare(strict_types=1);

namespace App\Onboarding\Http;

use App\Identity\Domain\CurrentUser;
use App\Onboarding\Application\DeactivateLink;
use App\Onboarding\Application\GeneratePlayerLink;
use App\Onboarding\Application\RegeneratePlayerLink;
use App\Onboarding\Domain\ShareLinkRepository;
use App\Shared\Http\AppUrl;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Redirect;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Trainer's ShareLink page. The organization is always the one of the request context (never
 * taken from the URL), so one Trainer cannot reach another's link.
 */
final class TrainerLinksController
{
    private const NOTICES = [
        'generated' => 'Your player link is ready.',
        'regenerated' => 'A new player link was created. The old link no longer works.',
        'deactivated' => 'The player link was turned off. Anyone opening it now sees a "link not valid" message.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ShareLinkRepository $links,
        private readonly GeneratePlayerLink $generateLink,
        private readonly RegeneratePlayerLink $regenerateLink,
        private readonly DeactivateLink $deactivateLink,
        private readonly ClientIp $clientIp,
        private readonly AppUrl $urls,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContext::from($request);
        $organization = $context->organizationId ?? throw new NotFound();
        $link = $this->links->findActivePlayerLink($organization);
        $notice = ((array) $request->getQueryParams())['notice'] ?? null;

        return $this->view->render('trainer/links.twig', [
            'page_title' => 'Player link',
            'link' => $link,
            'url' => $link === null ? null : $this->urls->absolute('/join/' . $link->code),
            'flash' => is_string($notice) ? (self::NOTICES[$notice] ?? null) : null,
        ]);
    }

    public function generate(ServerRequestInterface $request): ResponseInterface
    {
        $this->generateLink->execute($this->actor($request), RequestContext::from($request), $this->clientIp->fromRequest($request));

        return $this->redirect->to('/trainer/links?notice=generated');
    }

    public function regenerate(ServerRequestInterface $request): ResponseInterface
    {
        $this->regenerateLink->execute($this->actor($request), RequestContext::from($request), $this->clientIp->fromRequest($request));

        return $this->redirect->to('/trainer/links?notice=regenerated');
    }

    public function deactivate(ServerRequestInterface $request): ResponseInterface
    {
        $this->deactivateLink->execute($this->actor($request), RequestContext::from($request), $this->clientIp->fromRequest($request));

        return $this->redirect->to('/trainer/links?notice=deactivated');
    }

    private function actor(ServerRequestInterface $request): CurrentUser
    {
        $actor = $request->getAttribute('current_user');

        return $actor instanceof CurrentUser ? $actor : throw new Forbidden();
    }
}
