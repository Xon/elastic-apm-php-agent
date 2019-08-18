<?php

namespace PhilKra\Events;

use PhilKra\Helper\Timer;

/**
 *
 * A Span inside a transaction
 *
 * @link https://www.elastic.co/guide/en/apm/server/master/span-api.html
 *
 * "required": ["duration", "name", "type"]
 * "required": ["id", "transaction_id", "trace_id", "parent_id"]
 */
class Span extends EventBean implements \JsonSerializable
{
    /**
     * Span Name
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
    ];

    /**
     * @var Span|null
     */
    private $parentSpan;

    /**
     * Create the Transaction
     *
     * @param string      $name
     * @param array       $contexts
     * @param Transaction $transaction
     * @param Span|null   $parentSpan
     */
    public function __construct(string $name, array $contexts, Transaction $transaction, Span $parentSpan = null)
    {
        parent::__construct($contexts, $transaction);
        $this->setSpanName($name);
        $this->parentSpan = $parentSpan;
        $transaction->addSpan($this);
        $this->timer = new Timer();
    }

    /**
    * Start the Transaction
    *
    * @return void
    */
    public function start()
    {
        $this->transaction->pushActiveSpan($this);
        $this->summary['start'] = (float)((\microtime( true) * 1000000 - $this->getTransaction()->getTimestamp()) / 1000);
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
        // Stop the Timer
        $this->timer->stop();
        $this->transaction->popActiveSpan($this);

        // Store Summary
        $this->summary['duration']  = $duration ?? round($this->timer->getDurationInMilliseconds(), 3);
        $this->summary['backtrace'] = debug_backtrace($this->getTransaction()->getBacktraceLimit());
    }

    /**
    * Set the Transaction Name
    *
    * @param string $name
    *
    * @return void
    */
    public function setSpanName(string $name)
    {
        $this->name = $name;
    }

    /**
    * Get the Span Name
    *
    * @return string
    */
    public function getSpanName() : string
    {
        return $this->name;
    }

    /**
     * @return Transaction
     */
    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }

    /**
     * @return Span|null
     */
    public function getParentSpan(): ?Span
    {
        return $this->parentSpan;
    }

    /**
     * @param Span|null $parentSpan
     */
    public function setParentSpan(?Span $parentSpan): void
    {
        $this->parentSpan = $parentSpan;
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
    * Serialize Transaction Event
    *
    * @return array
    */
    public function jsonSerialize() : array
    {
        $jsonData = [
            'id'             => $this->getId(),
            'transaction_id' => $this->getTransaction()->getId(),
            'parent_id'      => $this->parentSpan ? $this->parentSpan->getId() : $this->getTransaction()->getId(),
            'trace_id'       => $this->getTransaction()->getId(),
            'name'           => $this->getSpanName(),
            'duration'       => $this->summary['duration'],
            'context'        => $this->getContext(),
            'stacktrace'     => $this->mapStacktrace($this->summary['backtrace']),
        ];

        $meta = $this->getMeta();
        unset($meta['result']);
        $jsonData = \array_merge($jsonData, $meta);

        return $jsonData;
    }
}
