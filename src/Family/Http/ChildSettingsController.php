<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Application\ChangeTokenSpendSetting;
use App\Family\Domain\PlayerProfileRepository;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The parent's per-child settings: "Allow token spending without approval" (AC-42, AC-43). Parent accounts only. */
final class ChildSettingsController
{
    use ActorAware;

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly PlayerProfileRepository $profiles,
        private readonly ChangeTokenSpendSetting $change,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function form(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $profile = $this->profiles->findOwned((string) ($args['id'] ?? ''), $this->actor($request)->id);
        if ($profile === null || !$profile->isChild()) {
            throw new NotFound();
        }
        $notice = ((array) $request->getQueryParams())['notice'] ?? null;

        return $this->view->render('family/children/settings.twig', [
            'page_title' => 'Settings for ' . $profile->firstName,
            'profile' => $profile,
            'flash' => $notice === 'saved' ? 'The setting was saved.' : null,
        ]);
    }

    /** @param array<string, string> $args */
    public function submit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        $enabled = ($this->body($request)['token_spend_without_approval'] ?? null) === '1';
        $this->change->execute($this->actor($request), $id, $enabled, $this->clientIp->fromRequest($request));

        return $this->redirect->to('/family/children/' . rawurlencode($id) . '/settings?notice=saved');
    }
}
