<?php

declare(strict_types=1);

namespace App\Organization\Http;

use App\Family\Http\ActorAware;
use App\Organization\Application\BrandingInput;
use App\Organization\Application\BrandingPreview;
use App\Organization\Application\ResetBranding;
use App\Organization\Application\UpdateBranding;
use App\Organization\Domain\BrandPalette;
use App\Organization\Domain\OrganizationBrandings;
use App\Shared\Domain\HexColor;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\RequestContext;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The Trainer's branding page (US-01.14): one logo and one primary color, a preview before saving
 * (live with JavaScript, a staged "Preview" submit without), and a reset to the default. The organization
 * is always the request context's, never the URL.
 */
final class BrandingController
{
    use ActorAware;

    private const NOTICES = [
        'saved' => 'Your branding was saved. Your players, coaches and parents see it now.',
        'reset' => 'Your branding was reset to the default.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly OrganizationBrandings $brandings,
        private readonly UpdateBranding $update,
        private readonly ResetBranding $reset,
        private readonly ClientIp $clientIp,
    ) {}

    public function form(ServerRequestInterface $request): ResponseInterface
    {
        $notice = ((array) $request->getQueryParams())['notice'] ?? null;

        return $this->render($request, null, [], 200, is_string($notice) ? (self::NOTICES[$notice] ?? null) : null);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $input = new BrandingInput(
            is_string($body['brand_color'] ?? null) ? trim($body['brand_color']) : '',
            $this->logoUpload($request),
            self::stagedToken($body['staged_logo'] ?? null),
        );
        $context = RequestContext::from($request);
        $actor = $this->actor($request);

        try {
            if (($body['action'] ?? '') === 'preview') {
                $preview = $this->update->preview($actor, $context, $input);

                return $this->render($request, $preview, [], 200, null, $input->colorText);
            }
            $this->update->execute($actor, $context, $input, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            return $this->render($request, null, $e->errors, 422, null, $input->colorText, $input->stagedLogo);
        }

        return $this->redirect->to('/trainer/branding?notice=saved');
    }

    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        $this->reset->execute($this->actor($request), RequestContext::from($request), $this->clientIp->fromRequest($request));

        return $this->redirect->to('/trainer/branding?notice=reset');
    }

    /** @param array<string, string> $errors */
    private function render(ServerRequestInterface $request, ?BrandingPreview $preview, array $errors, int $status, ?string $flash, ?string $colorText = null, ?string $staged = null): ResponseInterface
    {
        $organization = RequestContext::from($request)->organizationId ?? throw new NotFound();
        $saved = $this->brandings->find($organization) ?? throw new NotFound();
        $color = $colorText ?? ($saved->color->value ?? BrandPalette::DEFAULT_PRIMARY);
        // The live preview follows what is typed, when it is a valid color; otherwise the saved palette.
        try {
            $palette = $preview->palette ?? BrandPalette::fromColor(HexColor::fromString($color));
        } catch (ValidationFailed) {
            $palette = $saved->palette();
        }

        return $this->view->render('trainer/branding.twig', [
            'page_title' => 'Branding',
            'saved' => $saved,
            'preview' => $preview,
            'preview_palette' => $palette,
            'preview_logo' => $preview?->logoDataUri(),
            'staged_logo' => $preview->stagedLogo ?? $staged,
            'color' => $color,
            'errors' => $errors,
            'flash' => $flash,
            'default_color' => BrandPalette::DEFAULT_PRIMARY,
        ], $status);
    }

    private function logoUpload(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $file = $request->getUploadedFiles()['logo'] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    private static function stagedToken(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{32}\.(?:png|jpg|svg)$/', $value) === 1 ? $value : null;
    }
}
