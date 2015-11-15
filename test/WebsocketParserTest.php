<?php

namespace Aerys\Test;

use Aerys\Websocket\Code;
use Aerys\Websocket\Rfc6455Endpoint;

class WebsocketParserTest extends \PHPUnit_Framework_TestCase {
    function compile($opcode, $fin, $msg = "", $rsv = 0b000) {
        $len = strlen($msg);

        // FRRROOOO per RFC 6455 Section 5.2
        $w = chr(($fin << 7) | ($rsv << 4) | $opcode);

        // length as bits 2-2/4/6, with masking bit set
        if ($len > 0xFFFF) {
            $w .= "\xFF" . pack('J', $len);
        } elseif ($len > 0x7D) {
            $w .= "\xFE" . pack('n', $len);
        } else {
            $w .= chr($len | 0x80);
        }

        // 4 bit mask (random)
        $mask = "\xF4\x37\x7A\x9C";
        // apply mask
        $masked = $msg ^ str_repeat($mask, ($len + 3) >> 2);

        return $w . $mask . $masked;
    }

    /**
     * @dataProvider provideParserData
     */
    function testParser($msg, $expected) {
        $parser = Rfc6455Endpoint::parser(function ($parsed) use ($expected, &$executed, &$frame) {
            $keys = array_keys($parsed);
            sort($keys);
            $this->assertEquals([0, 1, 2], $keys);
            if ($parsed[0] == Rfc6455Endpoint::DATA) {
                foreach (array_slice($expected, $frame, null, true) as $cur => list($type)) {
                    if ($type == Rfc6455Endpoint::DATA) {
                        break;
                    }
                }
            } else {
                $cur = $frame;
            }
            if ($expected[$cur][0] != Rfc6455Endpoint::ERROR || $parsed[0] != Rfc6455Endpoint::DATA) {
                $this->assertEquals($expected[$cur][0], $parsed[0]);
            }
            $executed += 1;
            switch ($parsed[0]) {
                case Rfc6455Endpoint::ERROR:
                    $this->assertTrue(is_string($parsed[1]));
                    $this->assertEquals($expected[$cur][1], $parsed[2]);
                    break;
                case Rfc6455Endpoint::CONTROL:
                    $this->assertEquals($expected[$cur], $parsed);
                    break;
                case Rfc6455Endpoint::DATA:
                    static $pending;
                    $this->assertTrue(is_string($parsed[1]));
                    $this->assertTrue(is_bool($parsed[2]));
                    $pending .= $parsed[1];
                    if ($parsed[2]) { // terminated
                        $this->assertEquals($frame, $cur);
                        $this->assertEquals($expected[$cur][1], $pending);
                        $this->assertLessThanOrEqual(ceil(strlen($expected[$cur][1]) / (1 << 15)) ?: 1, $executed);
                        $pending = "";
                        break;
                    }
                    return;
            }
            $executed = count($expected) == ++$frame;
        }, ["emitThreshold" => 1 << 15, "validate_utf8" => true]);

        $frame = 0;
        $parser->send($msg);
        $this->assertTrue($executed);

        $executed = false; $frame = 0;
        // do not iterate 1 by 1 for big strings, that's too slow.
        for ($i = 0, $off = max(strlen($msg) << 6, 1), $it = ceil(strlen($msg) / $off); $i < $it; $i++) {
            $parser->send(substr($msg, $i * $off, $off));
        }
        if (array_reduce($expected, function($error, $expectation) { return $error || $expectation[0] === Rfc6455Endpoint::ERROR; }, false)) {
            $this->assertFalse($executed);
        } else {
            $this->assertTrue($executed);
        }
    }

    function provideParserData() {
        $return = [];

        // 0-13 -- basic text and binary frames with fixed lengths -------------------------------->

        foreach ([0 /* 0-1 */, 125 /* 2-3 */, 126 /* 4-5 */, 127 /* 6-7 */, 128 /* 8-9 */, 65535 /* 10-11 */, 65536 /* 12-13 */] as $length) {
            $data = str_repeat("*", $length);
            foreach ([Rfc6455Endpoint::OP_TEXT, Rfc6455Endpoint::OP_BIN] as $optype) {
                $input = $this->compile($optype, true, $data);
                $return[] = [$input, [[Rfc6455Endpoint::DATA, $data]]];
            }
        }

        // 14-17 - basic control frame parsing ---------------------------------------------------->

        foreach (["" /* 14 */, "Hello world!" /* 15 */, "\x00\xff\xfe\xfd\xfc\xfb\x00\xff" /* 16 */, str_repeat("*", 125) /* 17 */] as $data) {
            $input = $this->compile(Rfc6455Endpoint::OP_PING, true, $data);
            $return[] = [$input, [[Rfc6455Endpoint::CONTROL, $data, Rfc6455Endpoint::OP_PING]]];
        }

        // 18 ---- error conditions: using a non-terminated frame with a control opcode ----------->

        $input = $this->compile(Rfc6455Endpoint::OP_PING, false);
        $return[] = [$input, [[Rfc6455Endpoint::ERROR, Code::PROTOCOL_ERROR]]];

        // 19 ---- error conditions: using a standalone continuation frame with fin = true -------->

        $input = $this->compile(Rfc6455Endpoint::OP_CONT, true);
        $return[] = [$input, [[Rfc6455Endpoint::ERROR, Code::PROTOCOL_ERROR]]];

        // 20 ---- error conditions: using a standalone continuation frame with fin = false ------->

        $input = $this->compile(Rfc6455Endpoint::OP_CONT, false);
        $return[] = [$input, [[Rfc6455Endpoint::ERROR, Code::PROTOCOL_ERROR]]];

        // 21 ---- error conditions: using a continuation frame after a finished text frame ------->

        $input = $this->compile(Rfc6455Endpoint::OP_TEXT, true, "Hello, world!") . $this->compile(Rfc6455Endpoint::OP_CONT, true);
        $return[] = [$input, [[Rfc6455Endpoint::DATA, "Hello, world!"], [Rfc6455Endpoint::ERROR, Code::PROTOCOL_ERROR]]];

        // 22-29 - continuation frame parsing ----------------------------------------------------->

        foreach ([[1, 0] /* 22-23 */, [126, 125] /* 24-25 */, [32767, 32769] /* 26-27 */, [32768, 32769] /* 28-29 */] as list($len1, $len2)) {
            // simple
            $input = $this->compile(Rfc6455Endpoint::OP_TEXT, false, str_repeat("*", $len1)) . $this->compile(Rfc6455Endpoint::OP_CONT, true, str_repeat("*", $len2));
            $return[] = [$input, [[Rfc6455Endpoint::DATA, str_repeat("*", $len1 + $len2)]]];

            // with interleaved control frame
            $input = $this->compile(Rfc6455Endpoint::OP_TEXT, false, str_repeat("*", $len1)) . $this->compile(Rfc6455Endpoint::OP_PING, true, "foo") . $this->compile(Rfc6455Endpoint::OP_CONT, true, str_repeat("*", $len2));
            $return[] = [$input, [[Rfc6455Endpoint::CONTROL, "foo", Rfc6455Endpoint::OP_PING], [Rfc6455Endpoint::DATA, str_repeat("*", $len1 + $len2)]]];
        }

        // 30 ---- error conditions: using a text frame after a not finished text frame ----------->

        $input = $this->compile(Rfc6455Endpoint::OP_TEXT, false, "Hello, world!") . $this->compile(Rfc6455Endpoint::OP_TEXT, true, "uhm, no!");
        $return[] = [$input, [[Rfc6455Endpoint::ERROR, Code::PROTOCOL_ERROR]]];

        // 31 ---- utf-8 validation must succeed for large utf-8 msgs ----------------------------->

        $data = "H".str_repeat("ö", 32770);
        $input = $this->compile(Rfc6455Endpoint::OP_TEXT, false, substr($data, 0, 32769)) . $this->compile(Rfc6455Endpoint::OP_CONT, true, substr($data, 32769));
        $return[] = [$input, [[Rfc6455Endpoint::DATA, $data]]];

        // 32 ---- utf-8 validation must succeed for interrupted utf-8 across frame boundary ------>

        $data = "H".str_repeat("ö", 32770);
        $input = $this->compile(Rfc6455Endpoint::OP_TEXT, false, substr($data, 0, 32768)) . $this->compile(Rfc6455Endpoint::OP_CONT, true, substr($data, 32768));
        $return[] = [$input, [[Rfc6455Endpoint::DATA, $data]]];

        // 33 ---- utf-8 validation must fail for bad utf-8 data (single frame) ------------------->

        $input = $this->compile(Rfc6455Endpoint::OP_TEXT, true, substr(str_repeat("ö", 2), 1));
        $return[] = [$input, [[Rfc6455Endpoint::ERROR, Code::INCONSISTENT_FRAME_DATA_TYPE]]];

        // 34 ---- utf-8 validation must fail for bad utf-8 data (multiple small frames) ---------->

        $data = "H".str_repeat("ö", 3);
        $input = $this->compile(Rfc6455Endpoint::OP_TEXT, false, substr($data, 0, 2)) . $this->compile(Rfc6455Endpoint::OP_CONT, true, substr($data, 3));
        $return[] = [$input, [[Rfc6455Endpoint::ERROR, Code::INCONSISTENT_FRAME_DATA_TYPE]]];

        // 35 ---- utf-8 validation must fail for bad utf-8 data (multiple big frames) ------------>

        $data = "H".str_repeat("ö", 32770);
        $input = $this->compile(Rfc6455Endpoint::OP_TEXT, false, substr($data, 0, 32769)) . $this->compile(Rfc6455Endpoint::OP_CONT, true, substr($data, 32770));
        $return[] = [$input, [[Rfc6455Endpoint::ERROR, Code::INCONSISTENT_FRAME_DATA_TYPE]]];

        // 36 ---- error conditions: using a too large payload with a control opcode -------------->

        $input = $this->compile(Rfc6455Endpoint::OP_PING, true, str_repeat("*", 126));
        $return[] = [$input, [[Rfc6455Endpoint::ERROR, Code::PROTOCOL_ERROR]]];

        // 37 ---- error conditions: unmasked data ------------------------------------------------>

        $input = substr($this->compile(Rfc6455Endpoint::OP_PING, true, str_repeat("*", 125)), 0, -4) & ("\xFF\x7F" . str_repeat("\xFF", 0xFF));
        $return[] = [$input, [[Rfc6455Endpoint::ERROR, Code::PROTOCOL_ERROR]]];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }
}