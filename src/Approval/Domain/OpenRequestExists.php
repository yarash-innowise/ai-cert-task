<?php

declare(strict_types=1);

namespace App\Approval\Domain;

/** The child already has an open request of this kind for this event (the unique index rejected a second). */
final class OpenRequestExists extends \RuntimeException {}
