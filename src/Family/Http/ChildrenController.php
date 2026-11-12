<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Application\AddChildToTrainer;
use App\Family\Application\ListChildren;
use App\Family\Application\RemoveChildFromTrainer;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Family overview, adding a profile to a Trainer, and removing it with confirmation (AC-33..AC-35). */
final class ChildrenController
{
    use ActorAware;

    private const NOTICES = [
        'child_updated' => 'The child profile was saved.',
        'child_created' => 'The child profile was created.',
        'child_created_with_trainers' => 'The child profile was created and added to your Trainer.',
        'added' => 'The Trainer was added.',
        'already' => 'This profile is already associated with that Trainer.',
        'removed' => 'The Trainer was removed. Upcoming RSVPs with this Trainer are being cancelled.',
        'login_enabled' => 'The child can now sign in with the email and password you set.',
        'me_created' => 'Your player profile was created.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly ListChildren $listChildren,
        private readonly AddChildToTrainer $addToTrainer,
        private readonly RemoveChildFromTrainer $removeFromTrainer,
        private readonly ClientIp $clientIp,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $notice = $request->getQueryParams()['notice'] ?? null;

        return $this->render($request, [], 200, is_string($notice) ? (self::NOTICES[$notice] ?? null) : null);
    }

    /** @param array<string, string> $args */
    public function addByCode(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        $code = $this->body($request)['code'] ?? '';
        try {
            $result = $this->addToTrainer->byShareLink($actor, (string) ($args['id'] ?? ''), is_string($code) ? $code : '', $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            return $this->render($request, $e->errors, 422, null, (string) ($args['id'] ?? ''));
        }

        return $this->redirect->to('/family/children?notice=' . ($result->added ? 'added' : 'already'));
    }

    /** @param array<string, string> $args */
    public function addByTrainer(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        $ids = $this->body($request)['organization_id'] ?? '';
        $result = $this->addToTrainer->byTrainers($actor, (string) ($args['id'] ?? ''), is_string($ids) && $ids !== '' ? [$ids] : [], $this->clientIp->fromRequest($request));
        $added = $result !== [] && $result[0]->added;

        return $this->redirect->to('/family/children?notice=' . ($added ? 'added' : 'already'));
    }

    /** @param array<string, string> $args */
    public function confirmRemove(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $preview = $this->removeFromTrainer->preview($this->actor($request), (string) ($args['id'] ?? ''), (string) ($args['organization'] ?? ''));

        return $this->view->render('family/remove-confirm.twig', [
            'page_title' => 'Remove ' . $preview->profile->firstName . ' from ' . $preview->association->businessName,
            'profile' => $preview->profile,
            'association' => $preview->association,
        ]);
    }

    /** @param array<string, string> $args */
    public function remove(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $this->removeFromTrainer->execute($this->actor($request), (string) ($args['id'] ?? ''), (string) ($args['organization'] ?? ''), $this->clientIp->fromRequest($request));

        return $this->redirect->to('/family/children?notice=removed');
    }

    /** @param array<string, string> $errors */
    private function render(ServerRequestInterface $request, array $errors, int $status, ?string $flash, string $errorProfileId = ''): ResponseInterface
    {
        $overview = $this->listChildren->execute($this->actor($request));

        return $this->view->render('family/children.twig', [
            'page_title' => 'Your children',
            'overview' => $overview,
            'errors' => $errors,
            'error_profile_id' => $errorProfileId,
            'flash' => $flash,
        ], $status);
    }
}
