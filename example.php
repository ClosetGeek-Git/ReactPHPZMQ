<?php

require __DIR__ . '/vendor/autoload.php';

$loop = new React\EventLoop\ZMQPollLoop();

React\EventLoop\Loop::set($loop);

$zmq_context = new ZMQContext();

$zmq_rep_socket = new ZMQSocket($zmq_context, ZMQ::SOCKET_REP);
$zmq_rep_socket->bind("inproc:///tmp/reactphp"); 

$zmq_req_socket = new ZMQSocket($zmq_context, ZMQ::SOCKET_REQ);
$zmq_req_socket->connect("inproc:///tmp/reactphp");

React\EventLoop\Loop::addReadStream($zmq_rep_socket, function ($zmq_rep_socket) {
    $message = $zmq_rep_socket->recv();
    React\EventLoop\Loop::addWriteStream($zmq_rep_socket, function ($zmq_rep_socket) use (&$message) {
        echo "Got message: $message\n";
        $zmq_rep_socket->send("HELLO BACK!!!");  
        React\EventLoop\Loop::removeWriteStream($zmq_rep_socket);
    });
});

$start = hrtime(true);
React\EventLoop\Loop::addPeriodicTimer(2, function () use ($zmq_req_socket, &$start) {   
    React\EventLoop\Loop::addWriteStream($zmq_req_socket, function ($zmq_req_socket) use (&$start) {   
        $zmq_req_socket->send("Hello World!");  
        React\EventLoop\Loop::addReadStream($zmq_req_socket, function ($zmq_req_socket) use (&$start) {
            $message = $zmq_req_socket->recv();
            echo "Got message: $message\n";
            React\EventLoop\Loop::removeReadStream($zmq_req_socket);
            $end = hrtime(true);
            echo (($end - $start))."\n";
            $start = $end;
        });
        React\EventLoop\Loop::removeWriteStream($zmq_req_socket);
    });
});

$server = stream_socket_server('tcp://127.0.0.1:8080');
if (!$server) {
    exit(1);
}

stream_set_blocking($server, false);

React\EventLoop\Loop::addReadStream($server, function ($server) {
    $client = stream_socket_accept($server);
    stream_set_blocking($client, false);
    $data = "HTTP/1.1 200 OK\r\nContent-Length: 14\r\n\r\nHellow World!\n";
    React\EventLoop\Loop::addWriteStream($client, function ($client) use (&$data) {
        $written = fwrite($client, $data);
        if ($written === strlen($data)) {
            fclose($client);
            React\EventLoop\Loop::removeWriteStream($client);
        } else {
            $data = substr($data, $written);
        }
    });
});

React\EventLoop\Loop::addPeriodicTimer(5, function () {
    $memory = memory_get_usage() / 1024;
    $formatted = number_format($memory, 3).'K';
    echo "Current memory usage: {$formatted}\n";
});

React\EventLoop\Loop::run();
