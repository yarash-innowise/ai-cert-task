<?php

declare(strict_types=1);

namespace App\Integration\Http;

use App\Identity\Domain\CurrentUser;
use App\Integration\Application\TokenBalance;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Stub pages for the player/child screens whose content belongs to later epics (G3): content
 * (Epic-04), progress and feedback requests (Epic-05), profile, tokens. They exist so the child
 * capability matrix (AC-46) is reachable and every page is scoped to the active Trainer context.
 */
final class FamilyAreaController
{
    public function __construct(private readonly View $view, private readonly TokenBalance $tokens) {}

    public function content(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stub('Content', 'Content assigned by your Trainer will appear here when the content feature is released.');
    }

    public function progress(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stub('Progress', 'Your progress will appear here when the progress feature is released.');
    }

    public function feedback(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stub('Feedback requests', 'You will be able to ask your Trainer for feedback here when the feedback feature is released.');
    }

    public function tokens(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('current_user');
        if (!$user instanceof CurrentUser) {
            throw new Forbidden();
        }

        return $this->view->render('integration/stub.twig', [
            'page_title' => 'Tokens',
            'message' => 'Your token balance with this Trainer.',
            'balance' => $this->tokens->balanceOf($user->id),
        ]);
    }

    /** Parent-only features that arrive with later epics (payments, purchases, account deletion). */
    public function reserved(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('integration/stub.twig', [
            'page_title' => 'Not available yet',
            'message' => 'This feature is not available yet.',
        ], 501);
    }

    private function stub(string $title, string $message): ResponseInterface
    {
        return $this->view->render('integration/stub.twig', ['page_title' => $title, 'message' => $message]);
    }
}
