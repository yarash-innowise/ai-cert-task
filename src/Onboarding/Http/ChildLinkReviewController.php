<?php

declare(strict_types=1);

namespace App\Onboarding\Http;

use App\Family\Application\AddChildToTrainer;
use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use App\Family\Domain\TrainerAssociations;
use App\Identity\Domain\CurrentUser;
use App\Onboarding\Application\ResolveShareLink;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `/family/join/{code}?child={profileId}`: the "Review Registration" page a parent reaches from the
 * child-link email (AC-49). Only the OWNING parent may open it or confirm; anyone else gets 404.
 * Route group: Player role, child logins refused by DenyChildLoginMiddleware.
 */
final class ChildLinkReviewController
{
    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ResolveShareLink $resolve,
        private readonly PlayerProfileRepository $profiles,
        private readonly TrainerAssociations $associations,
        private readonly AddChildToTrainer $addToTrainer,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        $child = $this->ownedChild($actor, $request->getQueryParams()['child'] ?? null);
        $resolved = $this->resolve->execute((string) ($args['code'] ?? ''));
        if ($resolved === null) {
            return $this->view->render('onboarding/invalid-link.twig', ['page_title' => 'Link not valid'], 404);
        }

        return $this->view->render('onboarding/review-registration.twig', [
            'page_title' => 'Review registration',
            'child' => $child,
            'business_name' => $resolved->businessName,
            'code' => $resolved->link->code,
            'already' => $this->associations->activeAssociation($child->id, $resolved->link->organizationId) !== null,
        ]);
    }

    /** @param array<string, string> $args */
    public function confirm(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        $child = $this->ownedChild($actor, ((array) $request->getParsedBody())['child'] ?? null);

        try {
            $result = $this->addToTrainer->byShareLink($actor, $child->id, (string) ($args['code'] ?? ''), $this->clientIp->fromRequest($request));
        } catch (ValidationFailed) {
            return $this->view->render('onboarding/invalid-link.twig', ['page_title' => 'Link not valid'], 404);
        }

        return $this->redirect->to('/family/children?notice=' . ($result->added ? 'added' : 'already'));
    }

    private function actor(ServerRequestInterface $request): CurrentUser
    {
        $actor = $request->getAttribute('current_user');

        return $actor instanceof CurrentUser ? $actor : throw new Forbidden();
    }

    private function ownedChild(CurrentUser $actor, mixed $id): PlayerProfile
    {
        $profile = is_string($id) ? $this->profiles->findOwned($id, $actor->id) : null;

        return $profile !== null && $profile->isChild() ? $profile : throw new NotFound();
    }
}
