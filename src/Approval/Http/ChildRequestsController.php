<?php

declare(strict_types=1);

namespace App\Approval\Http;

use App\Approval\Application\ListMyRequests;
use App\Approval\Application\RequestPurchase;
use App\Approval\Application\RequestRsvp;
use App\Approval\Application\RequestRsvpCancel;
use App\Approval\Application\RequestTokenSpend;
use App\Family\Http\ActorAware;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A child login's side of the approval workflow: start a USD purchase, RSVP or cancel an RSVP (each only
 * creates a request for the parent, AC-38, AC-48) and watch the status of its own requests (AC-40).
 * Epic-02's event pages post to these routes. Parents and other roles are refused by the use cases.
 */
final class ChildRequestsController
{
    use ActorAware;

    private const NOTICES = [
        'requested' => 'Your request was sent to your parent. It stays pending until your parent answers.',
        'spent' => 'The tokens were spent. Your parent was told.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ListMyRequests $list,
        private readonly RequestPurchase $purchase,
        private readonly RequestRsvp $rsvp,
        private readonly RequestRsvpCancel $rsvpCancel,
        private readonly RequestTokenSpend $tokenSpend,
        private readonly ClientIp $clientIp,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $notice = ((array) $request->getQueryParams())['notice'] ?? null;

        return $this->view->render('family/requests/mine.twig', [
            'page_title' => 'My requests',
            'requests' => $this->list->execute($this->actor($request)),
            'flash' => is_string($notice) ? (self::NOTICES[$notice] ?? null) : null,
        ]);
    }

    public function purchase(ServerRequestInterface $request): ResponseInterface
    {
        $input = NewRequestForm::fromArray($this->body($request));
        $this->purchase->execute($this->actor($request), RequestContext::from($request), $input, $this->clientIp->fromRequest($request));

        return $this->redirect->to('/family/requests?notice=requested');
    }

    public function tokenSpend(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $raw = is_string($body['tokens'] ?? null) ? trim($body['tokens']) : '';
        if (preg_match('/^\d{1,6}$/', $raw) !== 1) {
            throw new ValidationFailed(['tokens' => sprintf('Enter a whole number of tokens from 1 to %d.', RequestTokenSpend::MAX_TOKENS)]);
        }
        $input = NewRequestForm::fromArray($body);
        $outcome = $this->tokenSpend->execute($this->actor($request), RequestContext::from($request), $input, (int) $raw, $this->clientIp->fromRequest($request));
        if ($outcome->needsApproval()) {
            return $this->redirect->to('/family/requests?notice=requested');
        }
        if (!$outcome->wasSpent()) {
            throw new ValidationFailed(['tokens' => 'The tokens could not be spent. Check your token balance or try again later.']);
        }

        return $this->redirect->to('/family/requests?notice=spent');
    }

    public function rsvp(ServerRequestInterface $request): ResponseInterface
    {
        $input = NewRequestForm::fromArray($this->body($request));
        $this->rsvp->execute($this->actor($request), RequestContext::from($request), $input, $this->clientIp->fromRequest($request));

        return $this->redirect->to('/family/requests?notice=requested');
    }

    public function rsvpCancel(ServerRequestInterface $request): ResponseInterface
    {
        $input = NewRequestForm::fromArray($this->body($request));
        $this->rsvpCancel->execute($this->actor($request), RequestContext::from($request), $input, $this->clientIp->fromRequest($request));

        return $this->redirect->to('/family/requests?notice=requested');
    }
}
