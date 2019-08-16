<?php

namespace PhilKra\Exception\Transaction;

/**
 * Trying to create a nested Transaction
 */
class NestedTransactionException extends \Exception
{
    public function __construct(string $message = '', int $code = 0, Throwable $previous = null)
    {
        parent::__construct(sprintf('The transaction "%s" is not permitted to be nested.', $message), $code, $previous);
    }
}
