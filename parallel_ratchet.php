<?php

require __DIR__ . '/vendor/autoload.php';

use \parallel\{Runtime, Future, Channel, Events};

$max_workers = 16;
$broadcast = true;

class ParallelWebsocketTest implements Ratchet\MessageComponentInterface {
    public $clients = [];
    protected $react_loop;
    protected $zmq_main;
    protected $max_workers;

    public function __construct($react_loop, $zmq_main, $max_workers) {
        $this->react_loop = $react_loop;
        $this->zmq_main = $zmq_main;
        $this->max_workers = $max_workers;
    }

    public function onOpen(Ratchet\ConnectionInterface $conn): void {
        $this->clients[$conn->resourceId]['ci'] = $conn; 
        $this->clients[$conn->resourceId]['th'] = rand(0, $this->max_workers-1);
    }

    public function onMessage(Ratchet\ConnectionInterface $conn, $msg): void {
        $thread_id = $this->clients[$conn->resourceId]['th'];
        $msg = json_encode([$conn->resourceId, $msg]);       
        $this->react_loop->addWriteStream( $this->zmq_main, function ($zmq_main) use ($thread_id, $msg) {
            $identity = "thread-".$thread_id;
            $zmq_main->sendmulti([$identity, "", $msg]);            
            $this->react_loop->removeWriteStream($this->zmq_main);
        });
    }

    public function onClose(Ratchet\ConnectionInterface $conn): void {
        foreach ($this->clients as $key => $value) {
            if ($value['ci'] == $conn) {
                unset($this->clients[$key]);
                break;
            }
        }
    }

    public function onError(Ratchet\ConnectionInterface $conn, \Exception $e): void {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

$react_loop = new React\EventLoop\ZMQPollLoop();

$thread = function($thread_id) {
    $zmq_context = ZMQContext::acquire();

    $zmq_socket = new ZMQSocket($zmq_context, ZMQ::SOCKET_ROUTER);
    $zmq_socket->setSockOpt(ZMQ::SOCKOPT_IDENTITY, "thread-".$thread_id);
    $zmq_socket->connect("inproc:///tmp/reactphp");
    
    while (true) {
        $msg = $zmq_socket->recvMulti();
        $zmq_socket->sendmulti([$msg[0], $msg[1], $msg[2]]);
    }
};

$zmq_context = ZMQContext::acquire();

$zmq_main = new ZMQSocket($zmq_context, ZMQ::SOCKET_ROUTER);
$zmq_main->setSockOpt(ZMQ::SOCKOPT_IDENTITY, "mainloop");
$zmq_main->bind("inproc:///tmp/reactphp"); 

$sock = new React\Socket\Server("0.0.0.0:8080", $react_loop);

$bench_server = new ParallelWebsocketTest($react_loop, $zmq_main, $max_workers);

$server = new Ratchet\Server\IoServer(
            new Ratchet\Http\HttpServer( 
                new Ratchet\WebSocket\WsServer( $bench_server ) 
            ),
            $sock, $react_loop
         );

$react_loop->addReadStream($zmq_main, function ($zmq_main) use ($bench_server, $broadcast) {
    $message = $zmq_main->recvMulti();
    $message = json_decode($message[2]);
    $conn_resource_id = $message[0];

    if ($broadcast == true) {
        foreach ($bench_server->clients as $key => $value) {
            $value['ci']->send($message[1]);        
        }
    } else {
        $bench_server->clients[$conn_resource_id]['ci']->send($message[1]);
    }
});

for($i = 0; $i < $max_workers; $i++) {
    parallel\run($thread, [$i]);
}

$server->run();
