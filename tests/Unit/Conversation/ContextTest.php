<?php

declare(strict_types=1);

namespace FondBot\Tests\Unit\Conversation;

use FondBot\Channels\Chat;
use FondBot\Channels\User;
use FondBot\Tests\TestCase;
use FondBot\Channels\Channel;
use FondBot\Conversation\Context;

class ContextTest extends TestCase
{
    public function test(): void
    {
        $channel = $this->mock(Channel::class);
        $chat = $this->mock(Chat::class);
        $user = $this->mock(User::class);
        $items = ['foo' => 'bar'];

        $context = new Context($channel, $chat, $user, $items);

        $this->assertSame($channel, $context->getChannel());
        $this->assertSame($chat, $context->getChat());
        $this->assertSame($user, $context->getUser());
        $this->assertNull($context->getIntent());
        $this->assertNull($context->getInteraction());

        $this->assertSame('bar', $context->get('foo'));
        $this->assertNull($context->get('bar'));

        $context->set('bar', 'foo');
        $this->assertSame('foo', $context->get('bar'));

        $payload = [
            'intent' => null,
            'interaction' => null,
            'items' => [
                'foo' => 'bar',
                'bar' => 'foo',
            ],
        ];

        $this->assertSame($payload, $context->toArray());
    }
}
