<?php

declare(strict_types=1);

namespace App\Approval\Http;

use App\Approval\Application\Decision;
use App\Approval\Application\DecideRequest;
use App\Approval\Application\ListParentApprovals;
use App\Family\Http\ActorAware;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The parent's approvals: the inbox, one request, and the decision (approve, deny, ask for more
 * information, each with an optional note). Parent accounts only; the use cases answer 404 for any
 * request that is not one of the parent's own children's (AC-45).
 */
final class ApprovalController
{
    use ActorAware;

    private const NOTICES = [
        'approve' => 'You approved the request.',
        'deny' => 'You denied the request.',
        'info' => 'Your child was told you need more information. You can still approve or deny.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ListParentApprovals $list,
        private readonly DecideRequest $decide,
        private readonly ClientIp $clientIp,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('family/approvals/index.twig', [
            'page_title' => 'Approvals',
            'items' => $this->list->execute($this->actor($request)),
        ]);
    }

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $notice = ((array) $request->getQueryParams())['notice'] ?? null;
        $item = $this->list->find($this->actor($request), (string) ($args['id'] ?? ''));

        return $this->view->render('family/approvals/show.twig', [
            'page_title' => 'Approval request',
            'item' => $item,
            'flash' => is_string($notice) ? (self::NOTICES[$notice] ?? null) : null,
            'old' => [],
            'errors' => [],
        ]);
    }

    /** @param array<string, string> $args */
    public function decide(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        $id = (string) ($args['id'] ?? '');
        $body = $this->body($request);
        $decision = Decision::tryFrom(is_string($body['decision'] ?? null) ? $body['decision'] : '');
        $note = is_string($body['note'] ?? null) ? trim($body['note']) : '';
        if ($decision === null || mb_strlen($note) > DecideRequest::NOTE_MAX) {
            $item = $this->list->find($actor, $id);

            return $this->view->render('family/approvals/show.twig', [
                'page_title' => 'Approval request',
                'item' => $item,
                'old' => ['note' => $note],
                'errors' => $decision === null
                    ? ['decision' => 'Choose approve, deny or ask for more information.']
                    : ['note' => sprintf('The note must be %d characters or fewer.', DecideRequest::NOTE_MAX)],
            ], 422);
        }

        $this->decide->execute($actor, $id, $decision, $note, $this->clientIp->fromRequest($request));

        return $this->redirect->to('/family/approvals/' . rawurlencode($id) . '?notice=' . $decision->value);
    }
}
