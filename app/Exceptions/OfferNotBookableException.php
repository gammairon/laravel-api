<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Rendered as 409 Conflict (see bootstrap/app.php).
 */
class OfferNotBookableException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function soldOut(): self
    {
        return new self('sold_out', 'The offer has no available units left.');
    }

    public static function expired(): self
    {
        return new self('expired', 'The offer has expired.');
    }

    public static function duplicateReference(): self
    {
        return new self('duplicate_client_reference', 'A reservation with this client_reference already exists.');
    }
}
