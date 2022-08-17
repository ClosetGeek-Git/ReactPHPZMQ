<?php

require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\{Loop, LoopInterface, ZMQPollLoop};
use \parallel\{Runtime, Future, Channel, Events};

$thread = function()
{
    $zmq_context = ZMQContext::acquire();

    $zmq_req_socket = new ZMQSocket($zmq_context, ZMQ::SOCKET_REQ);
    $zmq_req_socket->connect("inproc:///tmp/reactphp");

    $start = hrtime(true);
    
    while(true)
    {
        $zmq_req_socket->send("Hello World!");  
        $message = $zmq_req_socket->recv();
               
        $end = hrtime(true);
                
        echo (($end - $start))."\n";
        $start = $end;
    }
};

$zmq_context = ZMQContext::acquire();

$zmq_rep_socket = new ZMQSocket($zmq_context, ZMQ::SOCKET_REP);
$zmq_rep_socket->bind("inproc:///tmp/reactphp"); 

$server_loop = new ZMQPollLoop();

Loop::set($server_loop);

Loop::addReadStream($zmq_rep_socket, function ($zmq_rep_socket)
{
    $message = $zmq_rep_socket->recv();
    Loop::addWriteStream($zmq_rep_socket, function ($zmq_rep_socket) use (&$message)
    {
        //echo "Got message: $message\n";
        
        $zmq_rep_socket->send("HELLO BACK!!!");  

        Loop::removeWriteStream($zmq_rep_socket);
    });
});

for($i = 0; $i < 15; $i++)
{
    parallel\run($thread);
}

Loop::run();

?>
