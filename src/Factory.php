<?php

namespace React\MySQL;

use React\EventLoop\LoopInterface;
use React\MySQL\Commands\AuthenticateCommand;
use React\MySQL\Io\Connection;
use React\MySQL\Io\Executor;
use React\MySQL\Io\Parser;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\ConnectionInterface;

class Factory
{
    private $loop;
    private $connector;

    /**
     * The `Factory` is responsible for creating your [`ConnectionInterface`](#connectioninterface) instance.
     * It also registers everything with the main [`EventLoop`](https://github.com/reactphp/event-loop#usage).
     *
     * ```php
     * $loop = \React\EventLoop\Factory::create();
     * $factory = new Factory($loop);
     * ```
     *
     * If you need custom connector settings (DNS resolution, TLS parameters, timeouts,
     * proxy servers etc.), you can explicitly pass a custom instance of the
     * [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface):
     *
     * ```php
     * $connector = new \React\Socket\Connector($loop, array(
     *     'dns' => '127.0.0.1',
     *     'tcp' => array(
     *         'bindto' => '192.168.10.1:0'
     *     ),
     *     'tls' => array(
     *         'verify_peer' => false,
     *         'verify_peer_name' => false
     *     )
     * ));
     *
     * $factory = new Factory($loop, $connector);
     * ```
     *
     * @param LoopInterface $loop
     * @param ConnectorInterface|null $connector
     */
    public function __construct(LoopInterface $loop, ConnectorInterface $connector = null)
    {
        if ($connector === null) {
            $connector = new Connector($loop);
        }

        $this->loop = $loop;
        $this->connector = $connector;
    }

    /**
     * Creates a new connection.
     *
     * It helps with establishing a TCP/IP connection to your MySQL database
     * and issuing the initial authentication handshake.
     *
     * ```php
     * $factory->createConnection($url)->then(
     *     function (ConnectionInterface $connection) {
     *         // client connection established (and authenticated)
     *     },
     *     function (Exception $e) {
     *         // an error occured while trying to connect or authorize client
     *     }
     * );
     * ```
     *
     * The method returns a [Promise](https://github.com/reactphp/promise) that
     * will resolve with a [`ConnectionInterface`](#connectioninterface)
     * instance on success or will reject with an `Exception` if the URL is
     * invalid or the connection or authentication fails.
     *
     * The `$url` parameter must contain the database host, optional
     * authentication, port and database to connect to:
     *
     * ```php
     * $factory->createConnection('user:secret@localhost:3306/database');
     * ```
     *
     * You can omit the port if you're connecting to default port `3306`:
     *
     * ```php
     * $factory->createConnection('user:secret@localhost/database');
     * ```
     *
     * If you do not include authentication and/or database, then this method
     * will default to trying to connect as user `root` with an empty password
     * and no database selected. This may be useful when initially setting up a
     * database, but likely to yield an authentication error in a production system:
     *
     * ```php
     * $factory->createConnection('localhost');
     * ```
     *
     * @param string $uri
     * @return PromiseInterface Promise<ConnectionInterface, Exception>
     */
    public function createConnection($uri)
    {
        $parts = parse_url('mysql://' . $uri);
        if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'mysql') {
            return \React\Promise\reject(new \InvalidArgumentException('Invalid connect uri given'));
        }

        $uri = $parts['host'] . ':' . (isset($parts['port']) ? $parts['port'] : 3306);
        return $this->connector->connect($uri)->then(function (ConnectionInterface $stream) use ($parts) {
            $executor = new Executor();
            $parser = new Parser($stream, $executor);

            $connection = new Connection($stream, $executor);
            $command = $executor->enqueue(new AuthenticateCommand(
                isset($parts['user']) ? $parts['user'] : 'root',
                isset($parts['pass']) ? $parts['pass'] : '',
                isset($parts['path']) ? ltrim($parts['path'], '/') : ''
            ));
            $parser->start();

            return new Promise(function ($resolve, $reject) use ($command, $connection, $stream) {
                $command->on('success', function () use ($resolve, $connection) {
                    $resolve($connection);
                });
                $command->on('error', function ($error) use ($reject, $stream) {
                    $reject($error);
                    $stream->close();
                });
            });
        }, function ($error) {
            throw new \RuntimeException('Unable to connect to database server', 0, $error);
        });
    }
}
