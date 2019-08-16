<?php

namespace PhilKra\Events;

/**
 *
 * Event Bean for Error wrapping
 *
 * @link https://www.elastic.co/guide/en/apm/server/6.2/errors.html
 *
 */
class Error extends EventBean implements \JsonSerializable
{
    /**
     * Error | Exception
     *
     * @link http://php.net/manual/en/class.throwable.php
     *
     * @var Throwable
     */
    private $throwable;

    /**
     * @param Throwable $throwable
     * @param array $contexts
     */
    public function __construct(\Throwable $throwable, array $contexts, ?Transaction $transaction = null)
    {
        parent::__construct($contexts, $transaction);
        $this->throwable = $throwable;
    }

    /**
     * Serialize Error Event
     *
     * @return array
     */
    public function jsonSerialize() : array
    {
        $result = [
            'id'        => $this->getId(),
            'timestamp' => $this->getTimestamp(),
            'context'   => $this->getContext(),
            'culprit'   => sprintf('%s:%d', $this->throwable->getFile(), $this->throwable->getLine()),
            'exception' => [
                'message'    => $this->throwable->getMessage(),
                'type'       => get_class($this->throwable),
                'code'       => $this->throwable->getCode(),
                'stacktrace' => $this->mapStacktrace($this->throwable->getTrace()),
            ],
            'processor' => [
                'event' => 'error',
                'name'  => 'error',
            ]
        ];

        if ( ! empty($this->transaction) ) {
            $result['transaction_id'] = $this->transaction->getId();
            $result['parent_id'] = $this->transaction->getId();
            $result['trace_id'] = $this->transaction->getId();
        }

        return $result;
    }
}
