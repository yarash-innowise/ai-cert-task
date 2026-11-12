<?php

declare(strict_types=1);

namespace App\Integration\Application;

/** What the payment capability (Epic-05) answered to an approved purchase. */
enum HandoffResult: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** The capability is not available yet or has not answered; the purchase is not paid. */
    case Pending = 'pending';
}
