<?php

require __DIR__ . '/vendor/autoload.php';

$loop = new React\EventLoop\ZMQPollLoop();

React\EventLoop\Loop::set($loop);

$socket = new React\Socket\ZMQConnector($loop, ["type" => ZMQ::SOCKET_REP]);
$socket->bind("ipc:///tmp/reactphp")->then(
    function (React\Socket\ConnectionInterface $connection) 
    {
        $connection->on('data', function ($data) use ($connection)
        {
            echo "Got data: {$data[0]} \n";
            $connection->write(["HELLO BACK!!!"]);
        });
        
        $connection->on('error', function (Exception $e)
        {
            echo 'error: ' . $e->getMessage();
        });    
    },
    function (Exception $error) 
    {
        echo "failed to connect due to {$error} \n";
    }
);

React\EventLoop\Loop::run();