<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Connection;

class Webhook extends Event
{
    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this
            ->setQueue(Event::WEBHOOK_QUEUE_NAME)
            ->setClass(Event::WEBHOOK_CLASS_NAME);
    }

    public function trigger(): string|bool
    {
        /** Filter out context and trim project to keep the payload small */
        $this->context = [];
        $this->project = new Document([
            '$id' => $this->project->getId(),
            '$internalId' => $this->project->getInternalId(),
        ]);

        return parent::trigger();
    }
}
