<?php

declare(strict_types=1);

namespace App\Admin\Http;

use App\Admin\Domain\ImpersonationContext;

/** Lets the layout (Twig `impersonation()`) see the impersonation of this request, when there is one. */
final class ImpersonationHolder
{
    private ?ImpersonationContext $context = null;

    public function set(?ImpersonationContext $context): void
    {
        $this->context = $context;
    }

    public function get(): ?ImpersonationContext
    {
        return $this->context;
    }
}
