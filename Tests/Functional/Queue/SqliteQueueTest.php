<?php
namespace Flownative\Jobqueue\Sqlite\Tests\Functional\Queue;

/*
 * This file is part of the Flownative.Jobqueue.Sqlite package.
 *
 * (c) Contributors to the package
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\Jobqueue\Sqlite\Queue\SqliteQueue;
use TYPO3\Flow\Configuration\ConfigurationManager;
use TYPO3\Jobqueue\Common\Queue\Message;

/**
 * Functional test for SqliteQueue
 */
class SqliteQueueTest extends \TYPO3\Flow\Tests\FunctionalTestCase
{
    /**
     * @var SqliteQueue
     */
    protected $queue;

    /**
     * Set up dependencies
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();
        $configurationManager = $this->objectManager->get(ConfigurationManager::class);

        $options = ['storageFolder' => FLOW_PATH_DATA . 'Temporary/SqliteQueue/'];
        $this->queue = new SqliteQueue('Test queue', $options);
    }

    /**
     * Clean up queue
     *
     * @return void
     */
    public function tearDown()
    {
        $this->queue->flushQueue();
    }

    /**
     * @test
     */
    public function publishAndWaitWithMessageWorks()
    {
        $message = new Message('Yeah, tell someone it works!');
        $this->queue->submit($message);

        $result = $this->queue->waitAndTake(1);
        $this->assertNotNull($result, 'wait should receive message');
        $this->assertEquals($message->getPayload(), $result->getPayload(), 'message should have payload as before');
    }

    /**
     * @test
     */
    public function waitForMessageTimesOut()
    {
        $result = $this->queue->waitAndTake(1);
        $this->assertNull($result, 'wait should return NULL after timeout');
    }

    /**
     * @test
     */
    public function peekReturnsNextMessagesIfQueueHasMessages()
    {
        $message = new Message('First message');
        $this->queue->submit($message);
        $message = new Message('Another message');
        $this->queue->submit($message);

        $results = $this->queue->peek(1);
        $this->assertEquals(1, count($results), 'peek should return a message');
        $result = $results[0];
        $this->assertEquals('First message', $result->getPayload());
        $this->assertEquals(Message::STATE_SUBMITTED, $result->getState());

        $results = $this->queue->peek(1);
        $this->assertEquals(1, count($results), 'peek should return a message again');
        $result = $results[0];
        $this->assertEquals('First message', $result->getPayload(), 'second peek should return the same message again');
    }

    /**
     * @test
     */
    public function peekReturnsNullIfQueueHasNoMessage()
    {
        $result = $this->queue->peek();
        $this->assertEquals(array(), $result, 'peek should not return a message');
    }

    /**
     * @test
     */
    public function waitAndReserveWithFinishRemovesMessage()
    {
        $message = new Message('First message');
        $this->queue->submit($message);

        $result = $this->queue->waitAndReserve(1);
        $this->assertNotNull($result, 'waitAndReserve should receive message');
        $this->assertEquals($message->getPayload(), $result->getPayload(), 'message should have payload as before');

        $result = $this->queue->peek();
        $this->assertEquals(array(), $result, 'no message should be present in queue');

        $finishResult = $this->queue->finish($message);
        $this->assertTrue($finishResult);
    }
}
