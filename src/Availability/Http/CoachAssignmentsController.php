<?php

declare(strict_types=1);

namespace App\Availability\Http;

use App\Availability\Application\AcceptAssignment;
use App\Availability\Application\ListAssignments;
use App\Availability\Application\RequestAssignmentChange;
use App\Family\Http\ActorAware;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The Coach's dashboard: their assignments (overridden ones included), accept or ask for a change (AC-67). */
final class CoachAssignmentsController
{
    use ActorAware;

    private const NOTICES = [
        'accepted' => 'You accepted the assignment.',
        'change' => 'Your change request was sent to the Trainer.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ListAssignments $assignments,
        private readonly AcceptAssignment $accept,
        private readonly RequestAssignmentChange $requestChange,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $notice = ((array) $request->getQueryParams())['notice'] ?? null;

        return $this->render($request, [], 200, is_string($notice) ? (self::NOTICES[$notice] ?? null) : null);
    }

    /** @param array<string, string> $args */
    public function accept(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $this->accept->execute($this->actor($request), RequestContext::from($request), (string) ($args['id'] ?? ''));

        return $this->redirect->to('/coach?notice=accepted');
    }

    /** @param array<string, string> $args */
    public function requestChange(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $note = $this->body($request)['note'] ?? '';
        try {
            $this->requestChange->execute($this->actor($request), RequestContext::from($request), (string) ($args['id'] ?? ''), is_string($note) ? $note : '');
        } catch (ValidationFailed $e) {
            return $this->render($request, $e->errors, 422, null);
        }

        return $this->redirect->to('/coach?notice=change');
    }

    /** @param array<string, string> $errors */
    private function render(ServerRequestInterface $request, array $errors, int $status, ?string $flash): ResponseInterface
    {
        return $this->view->render('coach/dashboard.twig', [
            'page_title' => 'Coach dashboard',
            'assignments' => $this->assignments->forCoach($this->actor($request), RequestContext::from($request)),
            'errors' => $errors,
            'flash' => $flash,
        ], $status);
    }
}
