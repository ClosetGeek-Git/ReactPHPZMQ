ZMQPoll based LoopInterface for ReactPHP EventLoop. Somewhat faster than default StreamSelect ReactPHP eventloop when using standard PHP stream sockets but also supports first-class evented ZMQSockets (see example.php).

Also included is an example of using a ZMQPoll React eventloop to act as a reactor for parallel based PHP threads over ZMQ inproc:// transport. On my Ryzen 9 I'm able to communicate between 15 threads at once with round trips taking only 230.75 microseconds. That's 115 microsecond latency per event on each thread - which is 7.6 microseconds per event on the ReactPHP mainloop 😎 This was ran for an hour, during the PHP process only took 19% of my CPU and stayed at a constant 44.2 mb memory. This leaves 80% of my processor avalable for work if more is done within the threads than just echoing.

Ratchet Websocket benchmarks were performed using method found [here](https://github.com/matttomasetti/PHP-Ratchet_Websocket-Benchmark-Server). Results provided as .png files.

parallel_ratchet.php is a simple multi-thread websocket example for handling websocket messages in a parallel php threadpool.
