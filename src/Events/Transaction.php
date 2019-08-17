<?php

namespace PhilKra\Events;

use PhilKra\Helper\Timer;

/**
 *
 * Abstract Transaction class for all inheriting Transactions
 *
 * @link https://www.elastic.co/guide/en/apm/server/master/transaction-api.html
 *
 */
class Transaction extends EventBean implements \JsonSerializable
{
    /**
     * Transaction Name
     *
     * @var string
     */
    private $name;

    /**
     * Transaction Timer
     *
     * @var \PhilKra\Helper\Timer
     */
    private $timer;

    /**
     * Summary of this Transaction
     *
     * @var array
     */
    private $summary = [
        'duration'  => 0.0,
        'backtrace' => null,
        'headers'   => []
    ];

    /**
     * The spans for the transaction
     *
     * @var Span[]
     */
    private $spans = [];

    /**
     * The errors for the transaction
     *
     * @var array
     */
    private $errors = [];

    /**
     * Backtrace Depth
     *
     * @var int
     */
    private $backtraceLimit = 0;

    /**
     * @var array
     */
    private $spanStack;

    /**
    * Create the Transaction
    *
    * @param string $name
    * @param array $contexts
    */
    public function __construct(string $name, array $contexts, $start = null)
    {
        parent::__construct($contexts);
        $this->setTransactionName($name);
        $this->timer = new Timer($start);
    }

    /**
    * Start the Transaction
    *
    * @return void
    */
    public function start()
    {
        $this->timer->start();
    }

    /**
     * Stop the Transaction
     *
     * @param integer|null $duration
     *
     * @return void
     */
    public function stop(int $duration = null)
    {
        while($this->spanStack)
        {
            /** @var Span $activeSpan */
            $activeSpan = array_pop($this->spanStack);
            if ($activeSpan)
            {
                $activeSpan->stop();
            }
        }

        // Stop the Timer
        $this->timer->stop();

        // Store Summary
        $this->summary['duration']  = $duration ?? round($this->timer->getDurationInMilliseconds(), 3);
        $this->summary['headers']   = (function_exists('xdebug_get_headers') === true) ? xdebug_get_headers() : [];
        $this->summary['backtrace'] = debug_backtrace($this->backtraceLimit);
    }

    /**
    * Set the Transaction Name
    *
    * @param string $name
    *
    * @return void
    */
    public function setTransactionName(string $name)
    {
        $this->name = $name;
    }

    /**
    * Get the Transaction Name
    *
    * @return string
    */
    public function getTransactionName() : string
    {
        return $this->name;
    }

    /**
    * Get the Summary of this Transaction
    *
    * @return array
    */
    public function getSummary() : array
    {
        return $this->summary;
    }

    /**
     * @param Span $span
     */
    public function addSpan(Span $span)
    {
        $this->spans[] = $span;
    }

    public function pushActiveSpan(Span $span)
    {
        if ($this->spanStack)
        {
            $lastSpan = end($this->spanStack);
            if ($lastSpan)
            {
                $span->setParentSpan($lastSpan);
            }
        }

        $this->spanStack[] = $span;
    }

    public function popActiveSpan(Span $span)
    {
        while ($this->spanStack)
        {
            $lastElement = array_pop($this->spanStack);
            if (!$lastElement || $lastElement->getId() === $span->getId())
            {
                break;
            }
        }
    }

    /**
     * Set the spans for the transaction
     *
     * @param Span[] $spans
     *
     * @return void
     */
    public function setSpans(array $spans)
    {
        $this->spans = $spans;
    }

    public function addError(Error $error)
    {
        $this->errors[] = $error;
    }

    public function setErrors(array $errors)
    {
        $this->errors = $errors;
    }

    public function getErrors()
    {
        return $this->errors ?? [];
    }

    /**
     * Set the Max Depth/Limit of the debug_backtrace method
     *
     * @link http://php.net/manual/en/function.debug-backtrace.php
     * @link https://github.com/philkra/elastic-apm-php-agent/issues/55
     *
     * @param int $limit [description]
     */
    public function setBacktraceLimit(int $limit)
    {
        $this->backtraceLimit = $limit;
    }

    public function getBacktraceLimit() :int
    {
        return $this->backtraceLimit;
    }

    /**
     * Get the spans from the transaction
     *
     * @param int $dropCount
     * @return array
     */
    private function getSerializedSpans(int &$dropCount): array
    {
        $spans = [];
        foreach ($this->spans as $span)
        {
            if ($span->isDropSpan())
            {
                $dropCount++;
                continue;
            }
            $spans[] = $span->jsonSerialize();
        }

        return $spans;
    }

    /**
    * Serialize Transaction Event
    *
    * @return array
    */
    public function jsonSerialize() : array
    {
        $dropCount = 0;
        $spans = $this->getSerializedSpans($dropCount);
        return [
          'id'        => $this->getId(),
          'trace_id'  => $this->getId(),
          'span_count' => [
              'started' => count($spans),
              'dropped' => $dropCount,
          ],
          'timestamp' => $this->getTimestamp(),
          'name'      => $this->getTransactionName(),
          'duration'  => $this->summary['duration'],
          'type'      => $this->getMetaType(),
          'result'    => $this->getMetaResult(),
          'context'   => $this->getContext(),
          'spans'     => $spans,
          'errors'    => $this->getErrors(),
          'processor' => [
              'event' => 'transaction',
              'name'  => 'transaction',
          ]
      ];
    }
}
