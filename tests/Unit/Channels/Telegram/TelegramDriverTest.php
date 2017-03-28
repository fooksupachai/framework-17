<?php

declare(strict_types=1);

namespace Tests\Unit\Channels\Drivers;

use Tests\TestCase;
use GuzzleHttp\Client;
use FondBot\Contracts\Channels\User;
use FondBot\Contracts\Filesystem\File;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use FondBot\Conversation\Keyboards\Button;
use GuzzleHttp\Exception\RequestException;
use FondBot\Channels\Telegram\TelegramUser;
use FondBot\Channels\Telegram\TelegramDriver;
use FondBot\Contracts\Channels\Message\Location;
use FondBot\Conversation\Keyboards\BasicKeyboard;
use FondBot\Contracts\Channels\Message\Attachment;
use FondBot\Channels\Telegram\TelegramOutgoingMessage;
use FondBot\Channels\Telegram\TelegramReceivedMessage;

/**
 * @property mixed|\Mockery\Mock|\Mockery\MockInterface guzzle
 * @property array                                      parameters
 * @property TelegramDriver                             telegram
 */
class TelegramDriverTest extends TestCase
{
    protected function setUp()
    {
        parent::setUp();

        $this->guzzle = $this->mock(Client::class);

        $this->telegram = new TelegramDriver($this->guzzle);
        $this->telegram->fill($this->parameters = ['token' => str_random()]);
    }

    public function test_getConfig()
    {
        $expected = ['token'];

        $this->assertEquals($expected, $this->telegram->getConfig());
    }

    public function test_getHeaders()
    {
        $this->telegram->fill($this->parameters, [], $headers = ['Token' => $this->faker()->uuid]);

        $this->assertSame($headers['Token'], $this->telegram->getHeader('Token'));
        $this->assertSame($headers, $this->telegram->getHeaders());
    }

    /**
     * @expectedException \FondBot\Channels\Exceptions\InvalidChannelRequest
     * @expectedExceptionMessage Invalid payload
     */
    public function test_verifyRequest_empty_message()
    {
        $this->telegram->verifyRequest();
    }

    /**
     * @expectedException \FondBot\Channels\Exceptions\InvalidChannelRequest
     * @expectedExceptionMessage Invalid payload
     */
    public function test_verifyRequest_no_sender()
    {
        $this->telegram->fill($this->parameters, ['message' => []]);

        $this->telegram->verifyRequest();
    }

    public function test_verifyRequest()
    {
        $this->telegram->fill($this->parameters,
            ['message' => ['from' => $this->faker()->name, 'text' => $this->faker()->word]]);

        $this->telegram->verifyRequest();
    }

    public function test_installWebhook()
    {
        $url = $this->faker()->url;

        $this->guzzle->shouldReceive('post')->with(
            'https://api.telegram.org/bot'.$this->parameters['token'].'/setWebhook',
            [
                'form_params' => [
                    'url' => $url,
                ],
            ]
        )->once();

        $this->telegram->installWebhook($url);
    }

    public function test_getSender()
    {
        $this->telegram->fill($this->parameters, [
            'message' => [
                'from' => $response = [
                    'id' => str_random(),
                    'first_name' => $this->faker()->firstName,
                    'last_name' => $this->faker()->lastName,
                    'username' => $this->faker()->userName,
                ],
            ],
        ]);

        $sender = $this->telegram->getUser();
        $this->assertInstanceOf(User::class, $sender);
        $this->assertInstanceOf(TelegramUser::class, $sender);
        $this->assertSame($response['id'], $sender->getId());
        $this->assertSame($response['first_name'].' '.$response['last_name'], $sender->getName());
        $this->assertSame($response['username'], $sender->getUsername());
    }

    public function test_getMessage()
    {
        $this->telegram->fill($this->parameters, [
            'message' => [
                'text' => $text = $this->faker()->text,
            ],
        ]);

        /** @var TelegramReceivedMessage $message */
        $message = $this->telegram->getMessage();
        $this->assertInstanceOf(TelegramReceivedMessage::class, $message);
        $this->assertSame($text, $message->getText());
        $this->assertNull($message->getAttachment());
        $this->assertNull($message->getAudio());
        $this->assertNull($message->getDocument());
        $this->assertNull($message->getSticker());
        $this->assertNull($message->getVideo());
        $this->assertNull($message->getVoice());
        $this->assertNull($message->getContact());
        $this->assertNull($message->getLocation());
        $this->assertNull($message->getVenue());
    }

    /**
     * @dataProvider attachments
     *
     * @param string $type
     * @param array  $result
     */
    public function test_getMessage_with_attachments(string $type, array $result = null)
    {
        if ($result === null) {
            $result = [
                'file_id' => $id = $this->faker()->uuid,
            ];
        } else {
            $id = collect($result)->pluck('file_id')->last();
        }

        $this->telegram->fill($this->parameters, ['message' => [$type => $result]]);

        // Get file path from Telegram
        $response = $this->mock(ResponseInterface::class);
        $response->shouldReceive('getBody')->andReturnSelf();
        $response->shouldReceive('getContents')->andReturn(json_encode([
            'ok' => true,
            'result' => [
                'file_id' => $id,
                'file_size' => $this->faker()->randomFloat(),
                'file_path' => $path = $this->faker()->imageUrl(),
            ],
        ]));

        $this->guzzle->shouldReceive('post')
            ->with(
                'https://api.telegram.org/bot'.$this->parameters['token'].'/getFile',
                [
                    'form_params' => [
                        'file_id' => $id,
                    ],
                ]
            )
            ->andReturn($response)
            ->once();

        // Retrieve file contents
        $path = 'https://api.telegram.org/file/bot'.$this->parameters['token'].'/'.$path;
        $response = $this->mock(ResponseInterface::class);
        $response->shouldReceive('getBody')->andReturnSelf();
        $response->shouldReceive('getContents')->andReturn($contents = $this->faker()->text);
        $this->guzzle->shouldReceive('get')->with($path)->andReturn($response)->once();

        $message = $this->telegram->getMessage();
        $this->assertInstanceOf(TelegramReceivedMessage::class, $message);

        $attachment = $this->telegram->getMessage()->getAttachment();
        $this->assertInstanceOf(Attachment::class, $attachment);
        $this->assertSame($type, $attachment->getType());
        $this->assertSame($path, $attachment->getPath());
        $this->assertSame($contents, $attachment->getContents());
        $this->assertInstanceOf(File::class, $attachment->getFile());
        $this->assertSame(['type' => $type, 'path' => $path], $attachment->toArray());
    }

    public function test_getMessage_with_contact_full()
    {
        $this->telegram->fill($this->parameters, [
            'message' => [
                'contact' => $contact = [
                    'phone_number' => $phoneNumber = $this->faker()->phoneNumber,
                    'first_name' => $firstName = $this->faker()->firstName,
                    'last_name' => $lastName = $this->faker()->lastName,
                    'user_id' => $userId = $this->faker()->uuid,
                ],
            ],
        ]);

        /** @var TelegramReceivedMessage $message */
        $message = $this->telegram->getMessage();
        $this->assertInstanceOf(TelegramReceivedMessage::class, $message);
        $this->assertSame($contact, $message->getContact());

        $contact = $message->getContact();
        $this->assertSame($phoneNumber, $contact['phone_number']);
        $this->assertSame($firstName, $contact['first_name']);
        $this->assertSame($lastName, $contact['last_name']);
        $this->assertSame($userId, $contact['user_id']);
    }

    public function test_getMessage_with_contact_partial()
    {
        $this->telegram->fill($this->parameters, [
            'message' => [
                'contact' => $contact = [
                    'phone_number' => $phoneNumber = $this->faker()->phoneNumber,
                    'first_name' => $firstName = $this->faker()->firstName,
                ],
            ],
        ]);

        $contact = array_merge($contact, ['last_name' => null, 'user_id' => null]);

        /** @var TelegramReceivedMessage $message */
        $message = $this->telegram->getMessage();
        $this->assertInstanceOf(TelegramReceivedMessage::class, $message);
        $this->assertSame($contact, $message->getContact());

        $contact = $message->getContact();
        $this->assertSame($phoneNumber, $contact['phone_number']);
        $this->assertSame($firstName, $contact['first_name']);
        $this->assertNull($contact['last_name']);
        $this->assertNull($contact['user_id']);
    }

    public function test_getMessage_with_location()
    {
        $latitude = $this->faker()->latitude;
        $longitude = $this->faker()->longitude;

        $this->telegram->fill($this->parameters, [
            'message' => [
                'text' => $this->faker()->text,
                'location' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ],
            ],
        ]);

        $message = $this->telegram->getMessage();
        $this->assertInstanceOf(TelegramReceivedMessage::class, $message);

        $location = $message->getLocation();
        $this->assertInstanceOf(Location::class, $location);
        $this->assertSame($latitude, $location->getLatitude());
        $this->assertSame($longitude, $location->getLongitude());
    }

    public function test_getMessage_with_venue_full()
    {
        $latitude = $this->faker()->latitude;
        $longitude = $this->faker()->longitude;

        $this->telegram->fill($this->parameters, [
            'message' => [
                'text' => $this->faker()->text,
                'venue' => $venue = [
                    'location' => [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ],
                    'title' => $title = $this->faker()->title,
                    'address' => $address = $this->faker()->address,
                    'foursquare_id' => $foursquareId = $this->faker()->uuid,
                ],
            ],
        ]);

        $venue['location'] = new Location($venue['location']['latitude'], $venue['location']['longitude']);

        /** @var TelegramReceivedMessage $message */
        $message = $this->telegram->getMessage();
        $this->assertInstanceOf(TelegramReceivedMessage::class, $message);
        $this->assertEquals($venue, $message->getVenue());

        $venue = $message->getVenue();
        /** @var Location $location */
        $location = $venue['location'];
        $this->assertInstanceOf(Location::class, $location);
        $this->assertSame($latitude, $location->getLatitude());
        $this->assertSame($longitude, $location->getLongitude());
        $this->assertSame($title, $venue['title']);
        $this->assertSame($address, $venue['address']);
        $this->assertSame($foursquareId, $venue['foursquare_id']);
    }

    public function test_getMessage_with_venue_partial()
    {
        $latitude = $this->faker()->latitude;
        $longitude = $this->faker()->longitude;

        $this->telegram->fill($this->parameters, [
            'message' => [
                'text' => $this->faker()->text,
                'venue' => $venue = [
                    'location' => [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ],
                    'title' => $title = $this->faker()->title,
                    'address' => $address = $this->faker()->address,
                ],
            ],
        ]);

        $venue['location'] = new Location($venue['location']['latitude'], $venue['location']['longitude']);
        $venue['foursquare_id'] = null;

        /** @var TelegramReceivedMessage $message */
        $message = $this->telegram->getMessage();
        $this->assertInstanceOf(TelegramReceivedMessage::class, $message);
        $this->assertEquals($venue, $message->getVenue());

        $venue = $message->getVenue();
        /** @var Location $location */
        $location = $venue['location'];
        $this->assertInstanceOf(Location::class, $location);
        $this->assertSame($latitude, $location->getLatitude());
        $this->assertSame($longitude, $location->getLongitude());
        $this->assertSame($title, $venue['title']);
        $this->assertSame($address, $venue['address']);
        $this->assertNull($venue['foursquare_id']);
    }

    public function test_sendMessage_with_keyboard()
    {
        $text = $this->faker()->text;

        $recipient = $this->mock(User::class);
        $recipient->shouldReceive('getId')->andReturn($recipientId = $this->faker()->uuid)->atLeast()->once();
        $keyboard = new BasicKeyboard([
            new Button($this->faker()->word),
            new Button($this->faker()->word),
        ]);

        $replyMarkup = json_encode([
            'keyboard' => [
                [
                    (object) ['text' => $keyboard->getButtons()[0]->getLabel()],
                    (object) ['text' => $keyboard->getButtons()[1]->getLabel()],
                ],
            ],
            'resize_keyboard' => true,
        ]);

        $this->guzzle->shouldReceive('post')->with(
            'https://api.telegram.org/bot'.$this->parameters['token'].'/sendMessage',
            [
                'form_params' => [
                    'chat_id' => $recipientId,
                    'text' => $text,
                    'reply_markup' => $replyMarkup,
                ],
            ]
        )->once();

        $result = $this->telegram->sendMessage($recipient, $text, $keyboard);

        $this->assertInstanceOf(TelegramOutgoingMessage::class, $result);
        $this->assertSame($recipient, $result->getRecipient());
        $this->assertSame($text, $result->getText());
        $this->assertSame($keyboard, $result->getKeyboard());
    }

    public function test_sendMessage_without_keyboard()
    {
        $text = $this->faker()->text;

        $recipient = $this->mock(User::class);
        $recipient->shouldReceive('getId')->andReturn($recipientId = $this->faker()->uuid)->atLeast()->once();

        $this->guzzle->shouldReceive('post')->with(
            'https://api.telegram.org/bot'.$this->parameters['token'].'/sendMessage',
            [
                'form_params' => [
                    'chat_id' => $recipientId,
                    'text' => $text,
                ],
            ]
        )->once();

        $this->telegram->sendMessage($recipient, $text);
    }

    public function test_sendMessage_request_exception()
    {
        $text = $this->faker()->text;
        $recipient = $this->mock(User::class);
        $recipient->shouldReceive('getId')->andReturn($recipientId = $this->faker()->uuid)->atLeast()->once();

        $this->guzzle->shouldReceive('post')->andThrow(new RequestException('Invalid request',
            $this->mock(RequestInterface::class)));

        $this->telegram->sendMessage($recipient, $text);
    }

    public function attachments(): array
    {
        return [
            ['audio'],
            ['document'],
            [
                'photo',
                [
                    [
                        'file_id' => $this->faker()->uuid,
                        'file_size' => 1,
                        'file_path' => $this->faker()->imageUrl(),
                        'width' => $this->faker()->randomNumber(),
                        'height' => $this->faker()->randomNumber(),
                    ],
                    [
                        'file_id' => $this->faker()->uuid,
                        'file_size' => 2,
                        'file_path' => $this->faker()->imageUrl(),
                        'width' => $this->faker()->randomNumber(),
                        'height' => $this->faker()->randomNumber(),
                    ],
                    [
                        'file_id' => $this->faker()->uuid,
                        'file_size' => 3,
                        'file_path' => $this->faker()->imageUrl(),
                        'width' => $this->faker()->randomNumber(),
                        'height' => $this->faker()->randomNumber(),
                    ],
                ],
            ],
            ['sticker'],
            ['video'],
            ['voice'],
        ];
    }
}
