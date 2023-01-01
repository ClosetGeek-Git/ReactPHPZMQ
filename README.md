ZMQPoll based LoopInterface for ReactPHP EventLoop. Somewhat faster than default StreamSelect ReactPHP eventloop when operating on standard PHP stream sockets, but also supports first-class level triggered ZMQSockets (see `example.php`). 

Ratchet Websocket benchmarks were performed using method found [here](https://github.com/matttomasetti/PHP-Ratchet_Websocket-Benchmark-Server) to demonstrate the ZMQPoll EventLoop's ability to handle standard PHP streams such as for HTTP and Websocket. Results provided in [StreamSelect.png](https://github.com/ClosetMonkey/ReactPHPZMQ/blob/main/StreamSelect.png) and [ZMQPoll.png](https://github.com/ClosetMonkey/ReactPHPZMQ/blob/main/ZMQPoll.png) .png files.

Also included is an example of using a ZMQPoll React eventloop to act as a reactor for parallel based PHP threads over ZMQ inproc:// transport within `react_parallel.php`. On my Ryzen 9 I'm able to communicate between 15 threads at once with round trips taking only 230.75 microseconds. That's 115 microsecond latency per event on each thread - which is 7.6 microseconds per event on the ReactPHP mainloop 😎 This was ran for an hour, during the PHP process only took 19% of my CPU and stayed at a constant 44.2 mb memory. This leaves 80% of my processor available for work if more is done within the threads than just echoing.

`parallel_ratchet.php` is a simple multi-thread websocket example for handling websocket messages in a parallel php threadpool.

I also added ZMQ socket based React\Stream and React\Socket implementation. All seems to run well but haven't thoroughly tested. Any and all input is welcome!

Bound socket using ReactPHP\Stream and ReactPHP\Socket implmentations.
```php
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
```

Client socket
```php
<?php

require __DIR__ . '/vendor/autoload.php';

$loop = new React\EventLoop\ZMQPollLoop();

React\EventLoop\Loop::set($loop);

$socket = new React\Socket\ZMQConnector($loop, ["type" => ZMQ::SOCKET_REQ]);
$socket->connect("ipc:///tmp/reactphp")->then(
    function (React\Socket\ConnectionInterface $connection)
    {
        $connection->on('data', function ($data) use ($connection)
        {
            echo "Got data: {$data[0]} \n";
        });
        
        $connection->on('error', function (Exception $e)
        {
            echo 'error: ' . $e->getMessage();
        });    
    
        $connection->write(["hello"]);
    },
    function (Exception $error) {
        echo "failed to connect due to {$error} \n";
    }
);

React\EventLoop\Loop::run();
```
