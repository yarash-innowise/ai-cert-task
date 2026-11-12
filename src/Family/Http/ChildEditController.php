<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Application\EditChild;
use App\Family\Domain\PlayerProfile;
use App\Family\Domain\PlayerProfileRepository;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/** The parent edits a child profile, including its photo (AC-27, AC-71). Route group: Player role, child logins refused. */
final class ChildEditController
{
    use ActorAware;

    private const TEMPLATE = 'family/children/edit.twig';

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly PlayerProfileRepository $profiles,
        private readonly EditChild $editChild,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function form(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $profile = $this->owned($request, $args);

        return $this->render($profile, [
            'first_name' => $profile->firstName,
            'last_name' => $profile->lastName,
            'school' => $profile->school ?? '',
            'jersey_number' => $profile->jerseyNumber === null ? '' : (string) $profile->jerseyNumber,
        ], [], 200, null);
    }

    /** @param array<string, string> $args */
    public function submit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $profile = $this->owned($request, $args);
        $body = $this->body($request);
        try {
            $input = EditChildRequest::fromArray($body);
        } catch (ValidationFailed $e) {
            return $this->render($profile, $e->old, $e->errors, 422, null);
        }
        $photo = $request->getUploadedFiles()['photo'] ?? null;
        $photoError = $this->editChild->execute($this->actor($request), $profile->id, $input, $photo instanceof UploadedFileInterface ? $photo : null, $this->clientIp->fromRequest($request));

        if ($photoError !== null) {
            $fresh = $this->owned($request, $args);

            return $this->render($fresh, [
                'first_name' => $input->firstName,
                'last_name' => $input->lastName,
                'school' => $input->school ?? '',
                'jersey_number' => $input->jerseyNumber === null ? '' : (string) $input->jerseyNumber,
            ], ['photo' => $photoError], 422, 'Your other changes were saved. The photo was not changed.');
        }

        return $this->redirect->to('/family/children?notice=child_updated');
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function render(PlayerProfile $profile, array $old, array $errors, int $status, ?string $flash): ResponseInterface
    {
        return $this->view->render(self::TEMPLATE, [
            'page_title' => 'Edit ' . $profile->firstName,
            'profile' => $profile,
            'old' => $old,
            'errors' => $errors,
            'flash' => $flash,
        ], $status);
    }

    /** @param array<string, string> $args */
    private function owned(ServerRequestInterface $request, array $args): PlayerProfile
    {
        $profile = $this->profiles->findOwned((string) ($args['id'] ?? ''), $this->actor($request)->id);

        return $profile !== null && $profile->isChild() ? $profile : throw new NotFound();
    }
}
