<?php

namespace Appwrite\Event;

use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Phone extends Event
{
    protected string $recipient = '';
    protected string $message = '';

    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this
            ->setQueue(Event::MESSAGING_QUEUE_NAME)
            ->setClass(Event::MESSAGING_CLASS_NAME);
    }

    /**
     * Sets recipient for the messaging event.
     *
     * @param string $recipient
     * @return self
     */
    public function setRecipient(string $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Returns set recipient for this messaging event.
     *
     * @return string
     */
    public function getRecipient(): string
    {
        return $this->recipient;
    }

    /**
     * Sets url for the messaging event.
     *
     * @param string $message
     * @return self
     */
    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Returns set url for the messaging event.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Executes the event and sends it to the messaging worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        $client = new Client($this->queue, $this->connection);

        return $client->enqueue([
            'project' => $this->project,
            'user' => $this->user,
            'payload' => $this->payload,
            'recipient' => $this->recipient,
            'message' => $this->message,
            'events' => Event::generateEvents($this->getEvent(), $this->getParams())
        ]);
    }
}
