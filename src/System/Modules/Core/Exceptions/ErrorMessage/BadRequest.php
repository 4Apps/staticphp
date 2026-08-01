<?php

namespace System\Modules\Core\Exceptions\ErrorMessage;

use Throwable;
use System\Modules\Core\Exceptions\ErrorMessage;

/**
 * The request itself was malformed - 400.
 *
 * Used for things the client got wrong before routing even starts, such as an untrusted
 * or syntactically invalid Host header.
 */
class BadRequest extends ErrorMessage
{
    public function __construct(
        string $message = 'Bad Request',
        ?string $description = null,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            description: $description,
            previous: $previous,
            httpStatusCode: 400
        );
    }
}
