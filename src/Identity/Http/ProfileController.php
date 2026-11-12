<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Family\Http\ActorAware;
use App\Identity\Application\EditOwnProfile;
use App\Identity\Application\LoadOwnProfile;
use App\Identity\Application\OwnProfile;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The signed-in user's own profile (US-01.11): name, phone, photo and the fields of their role. The user is
 * always the signed-in one (no id in the address), so nobody edits anyone else's profile here. Email, role,
 * skill level and the creation date are shown, never bound (AC-69).
 */
final class ProfileController
{
    use ActorAware;

    private const NOTICES = ['saved' => 'Your profile was saved.'];

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly LoadOwnProfile $load,
        private readonly EditOwnProfile $edit,
        private readonly ClientIp $clientIp,
    ) {}

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        $profile = $this->load->execute($this->actor($request));
        $notice = ((array) $request->getQueryParams())['notice'] ?? null;

        return $this->render($profile, $this->valuesOf($profile), [], 200, is_string($notice) ? (self::NOTICES[$notice] ?? null) : null);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $profile = $this->load->execute($actor);

        try {
            $input = OwnProfileRequest::fromArray($this->body($request), $profile->sections);
        } catch (ValidationFailed $e) {
            return $this->render($profile, $e->old, $e->errors, 422, null);
        }

        $photo = $request->getUploadedFiles()['photo'] ?? null;
        $photoError = $this->edit->execute($actor, $input, $photo instanceof UploadedFileInterface ? $photo : null, $this->clientIp->fromRequest($request));
        if ($photoError !== null) {
            // The text was saved; only the photo was refused, so show the saved values and the photo's own error.
            $saved = $this->load->execute($actor);

            return $this->render($saved, $this->valuesOf($saved), ['photo' => $photoError], 422, 'Your other changes were saved. The photo was not changed.');
        }

        return $this->redirect->to('/profile?notice=saved');
    }

    /** @return array<string, mixed> */
    private function valuesOf(OwnProfile $profile): array
    {
        return ['name' => $profile->name, 'phone' => $profile->phone ?? '', ...$profile->values];
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function render(OwnProfile $profile, array $old, array $errors, int $status, ?string $flash): ResponseInterface
    {
        return $this->view->render('profile/edit.twig', [
            'page_title' => 'Profile',
            'profile' => $profile,
            'old' => $old,
            'errors' => $errors,
            'flash' => $flash,
        ], $status);
    }
}
