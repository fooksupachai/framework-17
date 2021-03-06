<?php

declare(strict_types=1);

namespace FondBot\Conversation\Activators;

use FondBot\Events\MessageReceived;
use FondBot\Contracts\Conversation\Activator;
use FondBot\Templates\Attachment as Template;

class Attachment implements Activator
{
    protected $type;

    public function __construct(string $type = null)
    {
        $this->type = $type;
    }

    public function file(): self
    {
        $this->type = Template::TYPE_FILE;

        return $this;
    }

    public function image(): self
    {
        $this->type = Template::TYPE_IMAGE;

        return $this;
    }

    public function audio(): self
    {
        $this->type = Template::TYPE_AUDIO;

        return $this;
    }

    public function video(): self
    {
        $this->type = Template::TYPE_VIDEO;

        return $this;
    }

    /**
     * Result of matching activator.
     *
     * @param MessageReceived $message
     *
     * @return bool
     */
    public function matches(MessageReceived $message): bool
    {
        if ($this->type === null) {
            return $message->getAttachment() !== null;
        }

        return hash_equals($message->getAttachment()->getType(), $this->type);
    }
}
