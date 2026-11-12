<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Policy\OrganizationPolicy;
use App\Organization\Domain\BrandPalette;
use App\Organization\Domain\Branding;
use App\Organization\Domain\OrganizationBrandings;
use App\Shared\Domain\HexColor;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\RequestContext;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Image\ProcessedImage;
use App\Shared\Infrastructure\Outbox\Outbox;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use App\Shared\Infrastructure\Storage\ImageStore;
use App\Shared\Infrastructure\Storage\StorageFailed;

/**
 * The Trainer saves the portal branding (AC-81..AC-84): validates the color and the logo first, stores the
 * new file, updates the row, and queues the old file's deletion through the outbox so it disappears only
 * after the change commits. Any failure leaves the previous logo and color exactly as they were, and a file
 * stored by the failing attempt is removed again.
 */
final class UpdateBranding
{
    public function __construct(
        private readonly OrganizationBrandings $brandings,
        private readonly OrganizationPolicy $policy,
        private readonly ImageStore $images,
        private readonly Outbox $outbox,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transaction,
    ) {}

    /**
     * @throws NotFound no active organization
     * @throws Forbidden not the Trainer of it
     * @throws ValidationFailed invalid color or logo (every message names its field)
     */
    public function execute(CurrentUser $actor, RequestContext $context, BrandingInput $input, ?string $ip = null): Branding
    {
        $organization = $this->authorized($actor, $context);
        [$color, $logo] = $this->validated($input);

        $newName = $logo === null ? null : $this->store($logo);
        try {
            $saved = $this->transaction->run(function () use ($actor, $organization, $color, $newName, $ip): Branding {
                $current = $this->brandings->lock($organization) ?? throw new NotFound();
                $logoPath = $newName ?? $current->logoPath;
                $this->brandings->save($organization, $logoPath, $color);
                if ($newName !== null) {
                    $this->images->deleteLogoAfterCommit($this->outbox, $current->logoPath);
                }
                $this->audit->log('branding.updated', $actor->id, 'organization', $organization->value, $organization->value, [
                    'logo_changed' => $newName !== null,
                    'color_changed' => $color->value !== $current->color?->value,
                ], null, $ip);

                return new Branding($organization, $current->businessName, $logoPath, $color);
            });
        } catch (\Throwable $e) {
            if ($newName !== null) {
                $this->images->discardLogo($newName);
            }
            throw $e;
        }
        if ($input->stagedLogo !== null) {
            $this->images->dropStaged($input->stagedLogo);
        }

        return $saved;
    }

    /**
     * The no-JavaScript "Preview" step: validates the same input and stages the processed logo, saving nothing.
     *
     * @throws ValidationFailed
     */
    public function preview(CurrentUser $actor, RequestContext $context, BrandingInput $input): BrandingPreview
    {
        $this->authorized($actor, $context);
        [$color, $logo] = $this->validated($input);
        $staged = $input->stagedLogo;
        if ($logo !== null) {
            try {
                $new = $this->images->stageLogo($logo);
            } catch (StorageFailed) {
                throw ValidationFailed::forField('logo', 'The logo could not be previewed. Please try again.');
            }
            if ($staged !== null) {
                $this->images->dropStaged($staged);
            }
            $staged = $new;
        }

        return new BrandingPreview(BrandPalette::fromColor($color), $logo, $staged);
    }

    private function authorized(CurrentUser $actor, RequestContext $context): OrganizationId
    {
        $organization = $context->organizationId ?? throw new NotFound();

        return $this->policy->canManage($actor, $context, $organization) ? $organization : throw new Forbidden();
    }

    /**
     * Validates everything before anything is stored, reporting the color and the logo problems together.
     *
     * @return array{HexColor, ProcessedImage|null}
     */
    private function validated(BrandingInput $input): array
    {
        $errors = [];
        $color = null;
        $logo = null;
        try {
            $color = HexColor::fromString($input->colorText);
        } catch (ValidationFailed $e) {
            $errors += $e->errors;
        }
        try {
            $logo = $this->logoOf($input);
        } catch (ValidationFailed $e) {
            $errors += $e->errors;
        }
        if ($errors !== [] || $color === null) {
            throw new ValidationFailed($errors);
        }

        return [$color, $logo];
    }

    private function logoOf(BrandingInput $input): ?ProcessedImage
    {
        if ($input->logo !== null && $input->logo->getError() !== UPLOAD_ERR_NO_FILE) {
            return $this->images->prepareLogo($input->logo);
        }
        if ($input->stagedLogo !== null) {
            return $this->images->staged($input->stagedLogo)
                ?? throw ValidationFailed::forField('logo', 'The preview has expired. Choose the logo file again.');
        }

        return null;
    }

    private function store(ProcessedImage $logo): string
    {
        try {
            return $this->images->storeLogo($logo);
        } catch (StorageFailed) {
            throw ValidationFailed::forField('logo', 'The logo could not be saved. Your previous logo is unchanged. Please try again.');
        }
    }
}
