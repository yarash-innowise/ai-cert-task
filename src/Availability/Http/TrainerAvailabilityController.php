<?php

declare(strict_types=1);

namespace App\Availability\Http;

use App\Availability\Application\PlayerAvailabilityQuery;
use App\Availability\Domain\Weekday;
use App\Family\Http\ActorAware;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Trainer reads their players' availability and filters it by a day and time (AC-63). The organization is
 * the request context's, never the URL; a player of another organization answers 404. Advisory only.
 */
final class TrainerAvailabilityController
{
    use ActorAware;

    public function __construct(private readonly View $view, private readonly PlayerAvailabilityQuery $query) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $dayInput = isset($params['day']) && is_string($params['day']) ? trim($params['day']) : '';
        $timeInput = isset($params['time']) && is_string($params['time']) ? trim($params['time']) : '';
        $day = $dayInput !== '' && ctype_digit($dayInput) ? Weekday::tryFrom((int) $dayInput) : null;
        $errors = [];
        if ($dayInput !== '' && $day === null) {
            $errors['day'] = 'Choose a day from the list.';
        }
        if ($timeInput !== '' && $day === null && $errors === []) {
            $errors['day'] = 'Choose a day to filter by time.';
        }

        $cards = [];
        $time = $timeInput;
        if ($errors === []) {
            try {
                $cards = $this->query->execute($this->actor($request), RequestContext::from($request), $day, $timeInput === '' ? null : $timeInput);
            } catch (\InvalidArgumentException $e) {
                $errors['time'] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            $day = null;
            $cards = $this->query->execute($this->actor($request), RequestContext::from($request));
        }

        return $this->view->render('trainer/players-availability.twig', [
            'page_title' => 'Player availability',
            'cards' => $cards,
            'days' => Weekday::cases(),
            'filter_day' => $day?->value,
            'filter_time' => $time,
            'filtered' => $day !== null,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $card = $this->query->find($this->actor($request), RequestContext::from($request), (string) ($args['id'] ?? ''));

        return $this->view->render('trainer/player-availability.twig', [
            'page_title' => 'Availability of ' . $card->player->name,
            'card' => $card,
            'rows' => WeeklyAvailabilityForm::rows($card->week),
        ]);
    }
}
