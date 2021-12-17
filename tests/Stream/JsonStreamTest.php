<?php
/**
 * This file is part of Swow
 *
 * @link    https://github.com/swow/swow
 * @contact twosee <twosee@php.net>
 *
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code
 */

declare(strict_types=1);

namespace SwowTest\Stream;

use PHPUnit\Framework\TestCase;
use Swow\Coroutine;
use Swow\Socket\Exception as SocketException;
use Swow\Stream\JsonStream;
use Swow\Sync\WaitReference;
use function getRandomBytes;
use const Swow\Errno\ECANCELED;
use const Swow\Errno\ECONNRESET;
use const TEST_MAX_CONCURRENCY_LOW;
use const TEST_MAX_REQUESTS_MID;

/**
 * @internal
 * @coversNothing
 */
class JsonStreamTest extends TestCase
{
    public function testServer()
    {
        $wr = new WaitReference();
        $server = new JsonStream();
        Coroutine::run(function () use ($server, $wr) {
            $server->bind('127.0.0.1')->listen();
            try {
                while (true) {
                    Coroutine::run(function (JsonStream $connection) {
                        try {
                            while (true) {
                                $json = $connection->recvJson();
                                $json = ['result' => $json['query']];
                                $connection->sendJson($json);
                            }
                        } catch (SocketException $exception) {
                            $this->assertContains($exception->getCode(), [0, ECONNRESET]);
                        }
                    }, $server->accept());
                }
            } catch (SocketException $exception) {
                $this->assertSame(ECANCELED, $exception->getCode());
            }
        });
        for ($c = 0; $c < TEST_MAX_CONCURRENCY_LOW; $c++) {
            $wrc = new WaitReference();
            Coroutine::run(function () use ($server, $wrc) {
                $client = new JsonStream();
                $client->connect($server->getSockAddress(), $server->getSockPort());
                for ($n = 0; $n < TEST_MAX_REQUESTS_MID; $n++) {
                    $random = getRandomBytes();
                    $client->sendJson(['query' => $random]);
                    $response = $client->recvJson();
                    $this->assertSame($response['result'], $random);
                }
                $client->close();
            });
            WaitReference::wait($wrc);
        }
        $server->close();
        WaitReference::wait($wr);
    }
}
