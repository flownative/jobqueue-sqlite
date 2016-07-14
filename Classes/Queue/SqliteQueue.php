<?php
namespace Flownative\JobQueue\Sqlite\Queue;

/*
 * This file is part of the Flownative.JobQueue.Sqlite package.
 *
 * (c) Contributors to the package
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Annotations as Flow;
use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;

/**
 * A queue implementation using Sqlite as the queue backend
 */
class SqliteQueue implements QueueInterface
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $storageFolder;

    /**
     * @var \SQLite3
     */
    protected $connection;

    /**
     * @var integer
     */
    protected $defaultTimeout = 60;

    /**
     * Constructor
     *
     * @param string $name
     * @param array $options
     * @throws \Flowpack\JobQueue\Common\Exception
     */
    public function __construct($name, array $options = array())
    {
        $this->name = $name;

        if (isset($options['defaultTimeout'])) {
            $this->defaultTimeout = $options['defaultTimeout'];
        }

        if (!isset($options['storageFolder'])) {
            throw new \Flowpack\JobQueue\Common\Exception('No storageFolder configured for SqliteQueue.', 1445527553);
        }
        $this->storageFolder = $options['storageFolder'];
    }

    /**
     * Lifecycle method
     *
     * @return void
     */
    protected function initializeObject()
    {
        $databaseFilePath = $this->storageFolder . md5($this->name) . '.db';
        $createDatabaseTables = FALSE;
        if (!is_file($databaseFilePath)) {
            if (!is_dir($this->storageFolder)) {
                mkdir($this->storageFolder, 0777, TRUE);
            }
            $createDatabaseTables = TRUE;
        }
        $this->connection = new \SQLite3($databaseFilePath);
        if ($createDatabaseTables) {
            $this->createQueueTables();
        }
    }

    /**
     * Publish a message to the queue
     *
     * @param Message $message
     * @return void
     */
    public function submit(Message $message)
    {

        if ($message->getIdentifier() !== NULL) {
            $preparedStatement = $this->connection->prepare('SELECT rowid FROM queue WHERE msgid=:msgid');
            $preparedStatement->bindValue(':msgid', $message->getIdentifier());
            $result = $preparedStatement->execute();
            if ($result->fetchArray(SQLITE3_NUM) !== FALSE) {
                return;
            }
        }

        $encodedMessage = $this->encodeMessage($message);

        $preparedStatement = $this->connection->prepare('INSERT INTO queue (msgid, payload) VALUES (:msgid, :payload);');
        $preparedStatement->bindValue(':msgid', $message->getIdentifier());
        $preparedStatement->bindValue(':payload', $encodedMessage);
        $preparedStatement->execute();
        $message->setIdentifier($this->connection->lastInsertRowID());
        $message->setState(Message::STATE_SUBMITTED);
    }

    /**
     * Wait for a message in the queue and return the message for processing
     *
     * @param int $timeout
     * @return Message The received message or NULL if a timeout occurred
     */
    public function waitAndTake($timeout = null)
    {
        $timeout = ($timeout !== null ? $timeout : $this->defaultTimeout);

        for ($time = 0; $time < $timeout; $time++) {
            $row = @$this->connection->querySingle('SELECT rowid, payload FROM queue ORDER BY rowid ASC LIMIT 1', true);
            if ($row !== []) {
                $preparedDelete = $this->connection->prepare('DELETE FROM queue WHERE rowid=:rowid');
                $preparedDelete->bindValue(':rowid', $row['rowid']);
                $preparedDelete->execute();

                $message = $this->decodeMessage($row['payload']);
                $message->setIdentifier($row['rowid']);

                // The message is marked as done
                $message->setState(Message::STATE_DONE);

                return $message;
            }

            sleep(1);
        }
        return null;
    }

    /**
     * Wait for a message in the queue and save the message to a safety queue
     *
     * @param int $timeout
     * @return Message
     */
    public function waitAndReserve($timeout = null)
    {
        $timeout = ($timeout !== null ? $timeout : $this->defaultTimeout);

        for ($time = 0; $time < $timeout; $time++) {
            $row = @$this->connection->querySingle('SELECT rowid, payload FROM queue ORDER BY rowid ASC LIMIT 1', true);
            if ($row !== []) {
                $message = $this->decodeMessage($row['payload']);
                $message->setIdentifier($row['rowid']);

                $encodedMessage = $this->encodeMessage($message);

                $preparedDelete = $this->connection->prepare('DELETE FROM queue WHERE rowid=:rowid');
                $preparedDelete->bindValue(':rowid', $row['rowid']);

                $preparedInsert = $this->connection->prepare('INSERT INTO processing (rowid, payload) VALUES (:rowid, :payload)');
                $preparedInsert->bindValue(':rowid', $row['rowid']);
                $preparedInsert->bindValue(':payload', $encodedMessage);

                try {
                    $this->connection->query('BEGIN IMMEDIATE TRANSACTION');
                    $preparedDelete->execute();
                    $preparedInsert->execute();
                    $this->connection->query('COMMIT');
                } catch (\Exception $exception) {
                    $this->connection->query('ROLLBACK');
                }

                return $message;
            }

            sleep(1);
        }

        return null;
    }

    /**
     * Mark a message as finished
     *
     * @param Message $message
     * @return boolean TRUE if the message could be removed
     */
    public function finish(Message $message)
    {
        $success = $this->connection->exec('DELETE FROM processing WHERE rowid=' . (int)$message->getIdentifier());
        if ($success) {
            $message->setState(Message::STATE_DONE);
        }
        return $success;
    }

    /**
     * Peek for messages
     *
     * @param integer $limit
     * @return array Messages or empty array if no messages were present
     */
    public function peek($limit = 1)
    {
        $messages = [];
        $result = $this->connection->query('SELECT * FROM queue ORDER BY rowid ASC LIMIT ' . (int)$limit);
        while ($resultRow = $result->fetchArray(SQLITE3_ASSOC)) {
            $message = $this->decodeMessage($resultRow['payload']);
            // The message is still published and should not be processed!
            $message->setState(Message::STATE_SUBMITTED);
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * Count messages in the queue
     *
     * @return integer
     */
    public function count()
    {
        return $this->connection->querySingle('SELECT COUNT(rowid) FROM queue');
    }

    /**
     * Flushes the queue. Danger, all queued items will be lost.
     *
     * This is a method primarily used in testing, not part of the API.
     *
     * @return void
     */
    public function flushQueue()
    {
        $databaseFilePath = $this->storageFolder . md5($this->name) . '.db';
        if (file_exists($databaseFilePath)) {
            unlink($databaseFilePath);
        }
        $this->initializeObject();
    }

    /**
     * @return void
     */
    protected function createQueueTables()
    {
        $this->connection->exec('CREATE TABLE queue (
            rowid INTEGER PRIMARY KEY AUTOINCREMENT,
            msgid VARCHAR,
            payload VARCHAR
        );');
        $this->connection->exec('CREATE TABLE processing (
            payload VARCHAR
        );');
    }

    /**
     * Encode a message
     *
     * Updates the original value property of the message to resemble the
     * encoded representation.
     *
     * @param Message $message
     * @return string
     */
    protected function encodeMessage(Message $message)
    {
        $value = json_encode($message->toArray());
        $message->setOriginalValue($value);
        return $value;
    }

    /**
     * Decode a message from a string representation
     *
     * @param string $value
     * @return Message
     */
    protected function decodeMessage($value)
    {
        $decodedMessage = json_decode($value, true);
        $message = new Message($decodedMessage['payload']);
        if (isset($decodedMessage['identifier'])) {
            $message->setIdentifier($decodedMessage['identifier']);
        }
        $message->setOriginalValue($value);
        return $message;
    }

    /**
     *
     * @param string $identifier
     * @return Message
     */
    public function getMessage($identifier)
    {
        return null;
    }
}
