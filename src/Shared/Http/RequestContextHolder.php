<?php

declare(strict_types=1);

namespace App\Shared\Http;

/** Lets the layout (Twig) see the RequestContext resolved for the current request. */
final class RequestContextHolder
{
    private RequestContext $context;

    public function __construct()
    {
        $this->context = RequestContext::none();
    }

    public function set(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function get(): RequestContext
    {
        return $this->context;
    }
}
