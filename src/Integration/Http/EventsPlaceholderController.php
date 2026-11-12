<?php

declare(strict_types=1);

namespace App\Integration\Http;

use App\Integration\Application\EventCatalog;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** View-only events pages. The catalog is a port, so the pages show whatever Epic-02 later provides. */
final class EventsPlaceholderController
{
    private const NOTICES = [
        'joined' => 'You are now associated with this Trainer.',
        'already_associated' => 'You are already associated with this Trainer.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly EventCatalog $catalog,
    ) {}

    /** Players and parents: events of all of their Trainers, or of the active context. */
    public function family(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContext::from($request);
        $notice = $request->getQueryParams()['notice'] ?? null;

        return $this->render('Events', $this->catalog->eligibleEvents($context->organizationId, $context->profileId), is_string($notice) ? (self::NOTICES[$notice] ?? null) : null);
    }

    /** Trainers: events of their own organization only. */
    public function trainer(ServerRequestInterface $request): ResponseInterface
    {
        $organization = RequestContext::from($request)->organizationId ?? throw new NotFound();

        return $this->render('Events', $this->catalog->eligibleEvents($organization));
    }

    /** @param list<\App\Integration\Application\EventSummary> $events */
    private function render(string $title, array $events, ?string $flash = null): ResponseInterface
    {
        return $this->view->render('integration/events.twig', ['page_title' => $title, 'events' => $events, 'flash' => $flash]);
    }
}
