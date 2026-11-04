<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Infrastructure\Serialization;

use AutoFlow\CoreBundle\Application\Serialization\SerializerInterface;
use Google\Protobuf\Internal\Message;
use InvalidArgumentException;

final class ProtobufSerializer implements SerializerInterface
{
    public function encode(mixed $value): string
    {
        if (!$value instanceof Message) {
            throw new InvalidArgumentException('The Protobuf serializer expects a generated Protobuf message.');
        }

        return $value->serializeToString();
    }

    /**
     * @param class-string<Message>|null $type
     */
    public function decode(string $payload, ?string $type = null): Message
    {
        if ($type === null || !is_subclass_of($type, Message::class)) {
            throw new InvalidArgumentException('A generated Protobuf message class must be supplied for decoding.');
        }

        /** @var class-string<Message> $messageClass */
        $messageClass = $type;
        $message = new $messageClass();
        $message->mergeFromString($payload);

        return $message;
    }
}
