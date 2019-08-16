<?php

namespace PhilKra;

use PhilKra\Events\DefaultEventFactory;
use PhilKra\Events\EventFactoryInterface;
use PhilKra\Events\Span;
use PhilKra\Events\Transaction;
use PhilKra\Exception\Transaction\NestedTransactionException;
use PhilKra\Exception\Transaction\NoTransactionInProgressException;
use PhilKra\Exception\Transaction\UnknownTransactionException;
use PhilKra\Helper\Config;
use PhilKra\Helper\Timer;
use PhilKra\Middleware\Connector;
use PhilKra\Stores\ErrorsStore;
use PhilKra\Stores\TransactionsStore;

/**
 *
 * APM Agent
 *
 * @link https://www.elastic.co/guide/en/apm/server/master/transaction-api.html
 *
 */
class Agent
{
    /**
     * Agent Version
     *
     * @var string
     */
    const VERSION = '7.1';

    /**
     * Agent Name
     *
     * @var string
     */
    const NAME = 'elastic-php';

    /**
     * Config Store
     *
     * @var \PhilKra\Helper\Config
     */
    private $config;

    /**
     * Transactions Store
     *
     * @var \PhilKra\Stores\TransactionsStore
     */
    private $transactionsStore;

    /**
     * Error Events Store
     *
     * @var \PhilKra\Stores\ErrorsStore
     */
    private $errorsStore;

    /**
     * Apm Timer
     *
     * @var \PhilKra\Helper\Timer
     */
    private $timer;

    /**
     * Common/Shared Contexts for Errors and Transactions
     *
     * @var array
     */
    private $sharedContext = [
      'user'   => [],
      'custom' => [],
      'tags'   => []
    ];

    /**
     * @var EventFactoryInterface
     */
    private $eventFactory;

    /**
     * @var \PhilKra\Events\Transaction
     */
    private $currentTransaction;

    /**
     * @return Transaction
     */
    public function getCurrentTransaction(): Transaction
    {
        return $this->currentTransaction;
    }

    /**
     * Setup the APM Agent
     *
     * @param array                 $config
     * @param array                 $sharedContext Set shared contexts such as user and tags
     * @param EventFactoryInterface $eventFactory  Alternative factory to use when creating event objects
     *
     * @return void
     */
    public function __construct(array $config, array $sharedContext = [], EventFactoryInterface $eventFactory = null, TransactionsStore $transactionsStore = null, ErrorsStore $errorsStore = null)
    {
        // Init Agent Config
        $this->config = new Config($config);

        // Use the custom event factory or create a default one
        $this->eventFactory = $eventFactory ?? new DefaultEventFactory();

        // Init the Shared Context
        $this->sharedContext['user']   = $sharedContext['user'] ?? [];
        $this->sharedContext['custom'] = $sharedContext['custom'] ?? [];
        $this->sharedContext['tags']   = $sharedContext['tags'] ?? [];

        // Let's misuse the context to pass the environment variable and cookies
        // config to the EventBeans and the getContext method
        // @see https://github.com/philkra/elastic-apm-php-agent/issues/27
        // @see https://github.com/philkra/elastic-apm-php-agent/issues/30
        $this->sharedContext['env'] = $this->config->get('env', []);
        $this->sharedContext['cookies'] = $this->config->get('cookies', []);

        // Initialize Event Stores
        $this->transactionsStore = $transactionsStore ?? new TransactionsStore();
        $this->errorsStore       = $errorsStore ?? new ErrorsStore();

        // Start Global Agent Timer
        $this->timer = new Timer();
        $this->timer->start();
    }

    /**
     * Start the Transaction capturing
     *
     * @throws \PhilKra\Exception\Transaction\DuplicateTransactionNameException
     *
     * @param string $name
     * @param array  $context
     *
     * @return Transaction
     */
    public function startTransaction(string $name, array $context = [], float $start = null): Transaction
    {
        if ($this->currentTransaction !== null) {
            // nested transactions are not supported in the protocol, need to use spans on the current transaction
            throw new NestedTransactionException($name);
        }

        // Create and Store Transaction
        $this->transactionsStore->register(
            $this->eventFactory->createTransaction($name, array_replace_recursive($this->sharedContext, $context), $start)
        );

        // Start the Transaction
        $transaction = $this->transactionsStore->fetch($name);

        if (null === $start) {
            $transaction->start();
        }

        $this->currentTransaction = $transaction;

        return $transaction;
    }

    /**
     * Stop the Transaction
     *
     * @throws \PhilKra\Exception\Transaction\UnknownTransactionException
     *
     * @param string $name
     * @param array $meta, Def: []
     *
     * @return void
     */
    public function stopTransaction(string $name, array $meta = [])
    {
        $transaction = $this->getTransaction($name);
        $transaction->setBacktraceLimit($this->config->get('backtraceLimit', 0));
        $transaction->stop();
        $transaction->setMeta($meta);

        $this->currentTransaction = null;
    }

    /**
     * Get a Transaction
     *
     * @throws \PhilKra\Exception\Transaction\UnknownTransactionException
     *
     * @param string $name
     *
     * @return Transaction
     */
    public function getTransaction(string $name)
    {
        $transaction = $this->transactionsStore->fetch($name);
        if ($transaction === null) {
            throw new UnknownTransactionException($name);
        }

        return $transaction;
    }

    public function startSpan(string $name, array $context = []): Span
    {
        if ($this->currentTransaction === null)
        {
            // nested transactions are not supported in the protocol, need to use spans on the current transaction
            throw new NoTransactionInProgressException($name);
        }

        $span = $this->eventFactory->createSpan($name, array_replace_recursive($this->sharedContext, $context), $this->currentTransaction);
        $span->start();

        return $span;
    }

    /**
     * Register a Thrown Exception, Error, etc.
     *
     * @link http://php.net/manual/en/class.throwable.php
     *
     * @param \Throwable $thrown
     * @param array      $context
     *
     * @return void
     */
    public function captureThrowable(\Throwable $thrown, array $context = [], ?Transaction $transaction = null)
    {
        $err = $this->eventFactory->createError($thrown, array_replace_recursive($this->sharedContext, $context), $transaction);

        if ( ! empty($transaction) ) {
            $transaction->addError($err);
            return;
        }

        $this->errorsStore->register(
            $err
        );
    }

    /**
     * Get the Agent Config
     *
     * @return \PhilKra\Helper\Config
     */
    public function getConfig() : \PhilKra\Helper\Config
    {
        return $this->config;
    }

    /**
     * Send Data to APM Service
     *
     * @link https://github.com/philkra/elastic-apm-laravel/issues/22
     * @link https://github.com/philkra/elastic-apm-laravel/issues/26
     *
     * @return bool
     */
    public function send() : bool
    {
        // Is the Agent enabled ?
        if ($this->config->get('active') === false) {
            $this->errorsStore->reset();
            $this->transactionsStore->reset();
            return true;
        }

        $connector = new Connector($this->config);
        $status = true;

        // Commit the Errors
        if ($this->errorsStore->isEmpty() === false) {
            $status = $status && $connector->sendErrors($this->errorsStore);
            if ($status === true) {
                $this->errorsStore->reset();
            }
        }

        // Commit the Transactions
        if ($this->transactionsStore->isEmpty() === false) {
            $status = $status && $connector->sendTransactions($this->transactionsStore);
            if ($status === true) {
                $this->transactionsStore->reset();
            }
        }

        return $status;
    }
}
