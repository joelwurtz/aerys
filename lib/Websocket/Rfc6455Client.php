<?php

namespace Aerys\Websocket;

use Amp\Struct;

class Rfc6455Client {
    use Struct;

    /** @var int */
    public $id;

    /** @var \Amp\Socket\ServerSocket */
    public $socket;

    /** @var \Generator */
    public $parser;
    public $msgEmitter;

    public $rateDeferred;

    public $pingCount = 0;
    public $pongCount = 0;

    // getInfo() properties
    public $connectedAt;
    public $closedAt = 0;
    public $lastReadAt = 0;
    public $lastSentAt = 0;
    public $lastDataReadAt = 0;
    public $lastDataSentAt = 0;
    public $bytesRead = 0;
    public $bytesSent = 0;
    public $framesRead = 0;
    public $framesSent = 0;
    public $messagesRead = 0;
    public $messagesSent = 0;

    public $capacity;
    public $framesLastSecond = 0;
}
