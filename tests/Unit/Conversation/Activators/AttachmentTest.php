<?php

declare(strict_types=1);

namespace FondBot\Tests\Unit\Conversation\Activators;

use FondBot\Tests\TestCase;
use FondBot\Events\MessageReceived;
use FondBot\Templates\Attachment as Template;
use FondBot\Conversation\Activators\Attachment;

class AttachmentTest extends TestCase
{
    public function testMatchesWithoutType(): void
    {
        $message = new MessageReceived($this->fakeChat(), $this->fakeUser(), '/start', null, Template::create('foo', 'bar'));

        $activator = new Attachment;

        $this->assertTrue($activator->matches($message));
    }

    public function testDoesNotMatchWithoutType(): void
    {
        $message = new MessageReceived($this->fakeChat(), $this->fakeUser(), '/start', null, null);

        $activator = new Attachment;

        $this->assertFalse($activator->matches($message));
    }

    /**
     * @dataProvider types
     *
     * @param string $type
     */
    public function testMatchesWithType(string $type): void
    {
        $activator = new Attachment($type);
        $attachment = Template::create($type, 'bar');

        $message = new MessageReceived($this->fakeChat(), $this->fakeUser(), '/start', null, $attachment);

        $this->assertTrue($activator->matches($message));
    }

    /**
     * @dataProvider types
     *
     * @param string $type
     */
    public function testDoesNotMatchWithType(string $type): void
    {
        $activator = new Attachment($type);
        $otherType = collect(Template::possibleTypes())
            ->filter(function ($item) use ($type) {
                return $item !== $type;
            })
            ->random();

        $attachment = Template::create($otherType, 'bar');

        $message = new MessageReceived($this->fakeChat(), $this->fakeUser(), '/start', null, $attachment);

        $this->assertFalse($activator->matches($message));
    }

    public function types(): array
    {
        return collect(Template::possibleTypes())
            ->map(function ($item) {
                return [$item];
            })
            ->toArray();
    }
}
