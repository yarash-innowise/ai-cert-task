<?php

declare(strict_types=1);

namespace App\Onboarding\Http;

use App\Identity\Domain\CurrentUser;
use App\Onboarding\Application\RegisterViaShareLink;
use App\Onboarding\Application\ResolveShareLink;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `/join/{code}/register`: a visitor who is not signed in registers through a Trainer's link
 * (AC-19, AC-20). An unusable link shows the same generic page as the landing page and creates
 * nothing (AC-26); a signed-in user is sent back to the landing page, which decides what to do.
 */
final class RegisterController
{
    private const TEMPLATE = 'onboarding/register.twig';

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ResolveShareLink $resolve,
        private readonly RegisterViaShareLink $register,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function form(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $code = (string) ($args['code'] ?? '');
        if ($request->getAttribute('current_user') instanceof CurrentUser) {
            return $this->redirect->to('/join/' . rawurlencode($code));
        }
        $resolved = $this->resolve->execute($code);
        if ($resolved === null) {
            return $this->invalid();
        }

        return $this->render($code, $resolved->businessName, [], [], 200);
    }

    /** @param array<string, string> $args */
    public function submit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $code = (string) ($args['code'] ?? '');
        if ($request->getAttribute('current_user') instanceof CurrentUser) {
            return $this->redirect->to('/join/' . rawurlencode($code));
        }
        $resolved = $this->resolve->execute($code);
        if ($resolved === null) {
            return $this->invalid();
        }

        $input = null;
        try {
            $input = RegisterRequest::fromArray((array) $request->getParsedBody());
            $this->register->execute($resolved, $input, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            $old = $e->old !== [] ? $e->old : self::oldOf($input);

            return $this->render($code, $resolved->businessName, $old, $e->errors, 422);
        }

        return $this->view->render('onboarding/registered.twig', [
            'page_title' => 'Check your email',
            'business_name' => $resolved->businessName,
            'email' => $input->email->value,
        ]);
    }

    private function invalid(): ResponseInterface
    {
        return $this->view->render('onboarding/invalid-link.twig', ['page_title' => 'Link not valid'], 404);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function render(string $code, string $businessName, array $old, array $errors, int $status): ResponseInterface
    {
        return $this->view->render(self::TEMPLATE, [
            'page_title' => 'Register with ' . $businessName,
            'code' => $code,
            'business_name' => $businessName,
            'old' => $old,
            'errors' => $errors,
        ], $status);
    }

    /** @return array<string, string> */
    private static function oldOf(?\App\Onboarding\Application\RegisterInput $input): array
    {
        if ($input === null) {
            return [];
        }

        return [
            'name' => $input->name,
            'email' => $input->email->value,
            'phone' => $input->phone->e164,
            'player_for' => $input->playerIsChild ? 'child' : 'me',
            'player_name' => $input->playerName,
            'birth_date' => $input->birthDate->format('Y-m-d'),
            'gender' => $input->gender->value,
        ];
    }
}
