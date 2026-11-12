<?php

declare(strict_types=1);

namespace App\Onboarding\Http;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\EmailAlreadyRegistered;
use App\Identity\Domain\Role;
use App\Onboarding\Application\AttachPrefill;
use App\Onboarding\Application\PrefillAttachment;
use App\Onboarding\Application\RegisterFromPrefill;
use App\Onboarding\Application\ResolvePrefill;
use App\Onboarding\Domain\RegistrationPrefill;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `/register/prefill/{token}`: the registration entry point of a camp/evaluation submission (AC-85).
 * A usable token shows the form pre-filled and registers the person with that Trainer. A tampered,
 * unknown, expired or consumed token gives an ordinary blank registration. An email that already
 * has an account is asked to sign in; the pre-fill is attached to that account only after sign-in.
 */
final class PrefillRegisterController
{
    private const TEMPLATE = 'onboarding/prefill-register.twig';

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ResolvePrefill $resolve,
        private readonly RegisterFromPrefill $register,
        private readonly AttachPrefill $attach,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function form(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        $prefill = $this->resolve->execute($token);
        $user = $request->getAttribute('current_user');
        if ($user instanceof CurrentUser) {
            return $this->signedIn($request, $user, $token, $prefill);
        }
        if ($prefill !== null && $this->resolve->accountExists($prefill)) {
            return $this->signInPrompt($token, $prefill);
        }

        return $this->render($token, $prefill, $prefill->fields ?? [], [], 200);
    }

    /** @param array<string, string> $args */
    public function submit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        if ($request->getAttribute('current_user') instanceof CurrentUser) {
            return $this->redirect->to('/register/prefill/' . rawurlencode($token));
        }
        $prefill = $this->resolve->execute($token);

        $input = null;
        try {
            $input = RegisterRequest::fromArray((array) $request->getParsedBody());
            $this->register->execute($prefill, $input, $this->clientIp->fromRequest($request));
        } catch (EmailAlreadyRegistered) {
            // Nothing was created. The pre-fill stays unused until the person signs in.
            return $prefill !== null ? $this->signInPrompt($token, $prefill) : $this->render($token, null, self::oldOf($input), ['email' => 'This email is already registered. Sign in instead.'], 422);
        } catch (ValidationFailed $e) {
            $old = $e->old !== [] ? $e->old : self::oldOf($input);

            return $this->render($token, $prefill, $old, $e->errors, 422);
        }

        return $this->view->render('onboarding/registered.twig', [
            'page_title' => 'Check your email',
            'business_name' => $prefill->businessName ?? '',
            'email' => $input->email->value,
        ]);
    }

    /**
     * Signed-in visitors: the GET only renders (a confirmation page or a read-only outcome); the
     * attach itself is the CSRF-protected POST {@see attach()} (F-04, AC-85).
     */
    private function signedIn(ServerRequestInterface $request, CurrentUser $user, string $token, ?RegistrationPrefill $prefill): ResponseInterface
    {
        if ($user->role !== Role::Player || $user->isChildLogin() || $prefill === null) {
            return $this->redirect->to($user->role->dashboardPath());
        }
        $outcome = $this->attach->preview($user, $prefill);
        if ($outcome === null) {
            return $this->view->render('onboarding/prefill-confirm.twig', [
                'page_title' => 'Join ' . $prefill->businessName,
                'business_name' => $prefill->businessName,
                'token' => $token,
            ]);
        }

        return $this->outcome($outcome, $prefill);
    }

    /** @param array<string, string> $args */
    public function attach(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $user = $request->getAttribute('current_user');
        $prefill = $this->resolve->execute((string) ($args['token'] ?? ''));
        if (!$user instanceof CurrentUser || $user->isChildLogin() || $prefill === null) {
            return $this->redirect->to($user instanceof CurrentUser ? $user->role->dashboardPath() : '/login');
        }

        return $this->outcome($this->attach->execute($user, $prefill, $this->clientIp->fromRequest($request)), $prefill);
    }

    private function outcome(PrefillAttachment $outcome, RegistrationPrefill $prefill): ResponseInterface
    {
        return match ($outcome) {
            PrefillAttachment::Attached => $this->redirect->to('/family/events?notice=joined'),
            PrefillAttachment::AlreadyAssociated => $this->redirect->to('/family/events?notice=already_associated'),
            PrefillAttachment::NeedsProfile => $this->redirect->to('/family/me'),
            PrefillAttachment::WrongAccount => $this->view->render('onboarding/prefill-wrong-account.twig', [
                'page_title' => 'This link is for another account',
                'business_name' => $prefill->businessName,
            ], 403),
        };
    }

    private function signInPrompt(string $token, RegistrationPrefill $prefill): ResponseInterface
    {
        return $this->view->render('onboarding/prefill-sign-in.twig', [
            'page_title' => 'Sign in to continue',
            'business_name' => $prefill->businessName,
            'token' => $token,
        ]);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function render(string $token, ?RegistrationPrefill $prefill, array $old, array $errors, int $status): ResponseInterface
    {
        $business = $prefill->businessName ?? '';

        return $this->view->render(self::TEMPLATE, [
            'page_title' => $business === '' ? 'Register' : 'Register with ' . $business,
            'token' => $token,
            'business_name' => $business,
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
