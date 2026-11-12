<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Application\RegisterSelfAsPlayer;
use App\Family\Domain\Gender;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** A parent account also acts as a player ("Me", AC-31). */
final class SelfProfileController
{
    use ActorAware;

    private const TEMPLATE = 'family/me.twig';

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly RegisterSelfAsPlayer $register,
        private readonly ClientIp $clientIp,
    ) {}

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render(self::TEMPLATE, ['page_title' => 'Train yourself', 'old' => [], 'errors' => []]);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $body = $this->body($request);
        $birth = is_string($body['birth_date'] ?? null) ? trim($body['birth_date']) : '';
        $genderRaw = is_string($body['gender'] ?? null) ? trim($body['gender']) : '';
        $old = ['birth_date' => $birth, 'gender' => $genderRaw];

        $date = BirthDate::parse($birth);
        if ($date === null) {
            throw (new ValidationFailed(['birth_date' => $birth === '' ? 'Birth date is required.' : 'Enter the birth date as YYYY-MM-DD.']))->forForm(self::TEMPLATE, $old);
        }
        try {
            $this->register->execute($actor, $date, Gender::tryFrom($genderRaw), $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            throw $e->forForm(self::TEMPLATE, $old);
        }

        return $this->redirect->to('/family/children?notice=me_created');
    }
}
