<?php

declare(strict_types=1);

namespace App\Availability\Http;

use App\Availability\Application\SaveAvailability;
use App\Availability\Application\SavePlayerAvailability;
use App\Family\Application\ListChildren;
use App\Family\Http\ActorAware;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A player or parent sets the weekly availability of one player profile, separately for each child (AC-61,
 * AC-62). The owner is checked per profile by the use case (a foreign profile answers 404, a child login 403).
 */
final class PlayerAvailabilityController
{
    use ActorAware;

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ListChildren $family,
        private readonly SavePlayerAvailability $availability,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('family/availability-index.twig', [
            'page_title' => 'Availability',
            'overview' => $this->family->execute($this->actor($request)),
        ]);
    }

    /** @param array<string, string> $args */
    public function form(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $view = $this->availability->view($this->actor($request), (string) ($args['id'] ?? ''));
        $notice = ((array) $request->getQueryParams())['notice'] ?? null;

        return $this->view->render('family/availability.twig', [
            'page_title' => 'Availability for ' . $view->profile->firstName,
            'profile' => $view->profile,
            'rows' => WeeklyAvailabilityForm::rows($view->week),
            'errors' => [],
            'flash' => $notice === 'saved' ? SaveAvailability::CONFIRMATION : null,
        ]);
    }

    /** @param array<string, string> $args */
    public function save(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        $id = (string) ($args['id'] ?? '');
        $body = $this->body($request);
        try {
            $profile = $this->availability->execute($actor, $id, WeeklyAvailabilityForm::parse($body));
        } catch (ValidationFailed $e) {
            $profile = $this->availability->view($actor, $id)->profile;

            return $this->view->render('family/availability.twig', [
                'page_title' => 'Availability for ' . $profile->firstName,
                'profile' => $profile,
                'rows' => $e->old['days'] ?? WeeklyAvailabilityForm::oldRows($body),
                'errors' => $e->errors,
                'flash' => null,
            ], 422);
        }

        return $this->redirect->to('/family/availability/' . rawurlencode($profile->id) . '?notice=saved');
    }
}
