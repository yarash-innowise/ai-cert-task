<?php

declare(strict_types=1);

namespace App\Availability\Http;

use App\Availability\Application\AssignCoach;
use App\Availability\Application\AssignCoachInput;
use App\Availability\Application\AssignOutcome;
use App\Availability\Application\ListAssignments;
use App\Availability\Domain\TimeRange;
use App\Family\Http\ActorAware;
use App\Organization\Application\ListOrganizationCoaches;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Trainer assigns a Coach to an event reference with a date and time window (events are Epic-02's). A conflict
 * with the Coach's schedule shows the warning (AC-65) and writes nothing; "Continue anyway" needs a reason.
 */
final class TrainerAssignmentController
{
    use ActorAware;

    private const NOTICES = ['assigned' => 'The Coach was assigned and has been notified.'];

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly AssignCoach $assign,
        private readonly ListAssignments $assignments,
        private readonly ListOrganizationCoaches $coaches,
        private readonly ClientIp $clientIp,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $notice = ((array) $request->getQueryParams())['notice'] ?? null;

        return $this->render($request, [], [], 200, is_string($notice) ? (self::NOTICES[$notice] ?? null) : null, null, null);
    }

    public function assign(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $old = [
            'event_ref' => self::text($body, 'event_ref'),
            'event_date' => self::text($body, 'event_date'),
            'starts_at' => self::text($body, 'starts_at'),
            'ends_at' => self::text($body, 'ends_at'),
            'coach_user_id' => self::text($body, 'coach_user_id'),
            'reason' => self::text($body, 'reason'),
        ];
        try {
            $input = $this->parse($body, $old);
            $outcome = $this->assign->execute($this->actor($request), RequestContext::from($request), $input, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            return $this->render($request, $old, $e->errors, 422, null, null, null);
        }

        return $this->answer($request, $outcome, $old);
    }

    /** @param array<string, string> $old */
    private function answer(ServerRequestInterface $request, AssignOutcome $outcome, array $old): ResponseInterface
    {
        if ($outcome->wasAssigned()) {
            return $this->redirect->to('/trainer/assignments?notice=assigned');
        }
        $errors = $outcome->reasonError === null ? [] : ['reason' => $outcome->reasonError];

        return $this->render($request, $old, $errors, $errors === [] ? 200 : 422, null, $outcome->warning, $outcome->reasonError);
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $old
     * @throws ValidationFailed
     */
    private function parse(array $body, array $old): AssignCoachInput
    {
        $errors = [];
        $ref = trim($old['event_ref']);
        if ($ref === '' || mb_strlen($ref) > 100) {
            $errors['event_ref'] = 'Enter the event reference (up to 100 characters).';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $old['event_date']);
        if ($date === false || $date->format('Y-m-d') !== $old['event_date']) {
            $errors['event_date'] = 'Enter the event date.';
        }
        $window = null;
        try {
            $window = TimeRange::fromStrings($old['starts_at'], $old['ends_at']);
        } catch (\InvalidArgumentException $e) {
            $errors['starts_at'] = $e->getMessage();
        }
        if ($old['coach_user_id'] === '') {
            $errors['coach_user_id'] = 'Choose a Coach.';
        }
        if ($errors !== [] || $date === false || $window === null) {
            throw new ValidationFailed($errors);
        }

        return new AssignCoachInput($ref, $date, $window, $old['coach_user_id'], ($body['continue_anyway'] ?? null) === '1', $old['reason']);
    }

    /**
     * @param array<string, string> $old
     * @param array<string, string> $errors
     */
    private function render(ServerRequestInterface $request, array $old, array $errors, int $status, ?string $flash, ?string $warning, ?string $reasonError): ResponseInterface
    {
        $context = RequestContext::from($request);

        return $this->view->render('trainer/assign-coach.twig', [
            'page_title' => 'Assign a Coach',
            'coaches' => $this->coaches->execute($this->actor($request), $context),
            'assignments' => $this->assignments->forTrainer($this->actor($request), $context),
            'old' => $old,
            'errors' => $errors,
            'flash' => $flash,
            'warning' => $warning,
            'reason_error' => $reasonError,
        ], $status);
    }

    /** @param array<array-key, mixed> $body */
    private static function text(array $body, string $key): string
    {
        return isset($body[$key]) && is_string($body[$key]) ? trim($body[$key]) : '';
    }
}
