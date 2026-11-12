<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Decides who may fetch a stored image (design C11). Logos: members of that organization. Photos: the
 * person themselves, their guardian, the Trainers and Coaches of an organization the person is
 * associated with, and a Super Admin. A denied or unknown resource is the same answer: null (404).
 */
interface MediaAccess
{
    public const KINDS = ['logo', 'user-photo', 'user-thumb', 'profile-photo', 'profile-thumb'];

    public function locate(string $viewerId, bool $viewerIsAdmin, string $kind, string $id): ?StoredMedia;
}
