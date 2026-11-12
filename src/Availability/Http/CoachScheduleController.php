<?php

declare(strict_types=1);

namespace App\Availability\Http;

use App\Availability\Application\SaveCoachSchedule;
use App\Availability\Application\ViewCoachSchedule;
use App\Family\Http\ActorAware;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A Coach's own recurring weekly schedule, "My Times" (AC-64): several slots per day. There is no id in the Coach's
 * address, so only their own week can be reached. A Trainer reads a Coach of their organization (404 otherwise).
 */
final class CoachScheduleController
{
    use ActorAware;

    public const CONFIRMATION = 'Your schedule was saved.';

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly SaveCoachSchedule $schedule,
        private readonly ViewCoachSchedule $trainerView,
    ) {}

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        $notice = ((array) $request->getQueryParams())['notice'] ?? null;

        return $this->render(WeeklyAvailabilityForm::rows($this->schedule->view($this->actor($request))), [], 200, $notice === 'saved' ? self::CONFIRMATION : null);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        try {
            $this->schedule->execute($this->actor($request), WeeklyAvailabilityForm::parse($body));
        } catch (ValidationFailed $e) {
            return $this->render($e->old['days'] ?? WeeklyAvailabilityForm::oldRows($body), $e->errors, 422, null);
        }

        return $this->redirect->to('/coach/schedule?notice=saved');
    }

    /** @param array<string, string> $args */
    public function showForTrainer(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $view = $this->trainerView->execute($this->actor($request), RequestContext::from($request), (string) ($args['id'] ?? ''));

        return $this->view->render('trainer/coach-schedule.twig', [
            'page_title' => 'Schedule of ' . $view->coach->name,
            'coach' => $view->coach,
            'rows' => WeeklyAvailabilityForm::rows($view->week),
            'has_entries' => $view->week->hasEntries(),
        ]);
    }

    /**
     * @param list<array{day: \App\Availability\Domain\Weekday, status: string, slots: list<array{start: string, end: string}>}> $rows
     * @param array<string, string> $errors
     */
    private function render(array $rows, array $errors, int $status, ?string $flash): ResponseInterface
    {
        return $this->view->render('coach/schedule.twig', ['page_title' => 'My Times', 'rows' => $rows, 'errors' => $errors, 'flash' => $flash], $status);
    }
}
