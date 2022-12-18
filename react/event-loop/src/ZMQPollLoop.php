<?php

namespace React\EventLoop;

use React\EventLoop\Tick\FutureTickQueue;
use React\EventLoop\Timer\Timer;
use React\EventLoop\Timer\Timers;

final class ZMQPollLoop implements LoopInterface{
    const MS  = 1000;
    const MUS = 1000000;

    private $futureTickQueue;
    private $timers;
    private $readStreams = array();
    private $readListeners = array();
    private $writeStreams = array();
    private $writeListeners = array();
    private $running;
    private $pcntl = false;
    private $pcntlPoll = false;
    private $signals;

    private $zmq_poller = null;

    public function __construct() {
        $this->futureTickQueue = new FutureTickQueue();
        $this->timers = new Timers();
        $this->pcntl = function_exists('pcntl_signal') && function_exists('pcntl_signal_dispatch');
        $this->pcntlPoll = $this->pcntl && !function_exists('pcntl_async_signals');
        $this->signals = new SignalsHandler();
        if ($this->pcntl && !$this->pcntlPoll) {
            pcntl_async_signals(true);
        }
        $this->zmq_poller = new \ZMQPoll();
    }
    
    public function addReadStream($stream, $listener) {
        if ($stream instanceof \ZMQSocket) {
            $key = spl_object_hash($stream);
        } else {
            $key = (int)$stream;
        }

        if (!isset($this->readStreams[$key])) {
            if (isset($this->writeStreams[$key])) {
                $this->zmq_poller->remove($this->writeStreams[$key]);
                $poll_id = $this->zmq_poller->add($stream, \ZMQ::POLL_IN | \ZMQ::POLL_OUT);    
                $this->writeStreams[$key] = $poll_id;
                $this->readStreams[$key] = $poll_id;

            } else {
                $poll_id = $this->zmq_poller->add($stream, \ZMQ::POLL_IN);
                $this->readStreams[$key] = $poll_id;
            }
            $this->readListeners[$key] = $listener;
        }
    }

    public function addWriteStream($stream, $listener) {
        if ($stream instanceof \ZMQSocket) {
            $key = spl_object_hash($stream);
        }else {
            $key = (int)$stream;
        }
        if (!isset($this->writeStreams[$key])) {

            if (isset($this->readStreams[$key])) {
                $this->zmq_poller->remove($this->readStreams[$key]);
                $poll_id = $this->zmq_poller->add($stream, \ZMQ::POLL_IN | \ZMQ::POLL_OUT);
                $this->readStreams[$key] = $poll_id;
                $this->writeStreams[$key] = $poll_id;
            } else {
                $poll_id = $this->zmq_poller->add($stream, \ZMQ::POLL_OUT);
                $this->writeStreams[$key] = $poll_id;
            }
            $this->writeListeners[$key] = $listener;
        }
    }

    public function removeReadStream($stream) {
        if ($stream instanceof \ZMQSocket) {
            $key = spl_object_hash($stream);
        } else {
            $key = (int)$stream;
        }
        if (isset($this->writeStreams[$key])) {
            $this->zmq_poller->remove($this->writeStreams[$key]);
            $poll_id = $this->zmq_poller->add($stream, \ZMQ::POLL_OUT);
            $this->writeStreams[$key] = $poll_id; 
        } else {
            $this->zmq_poller->remove($this->readStreams[$key]);
        }
        unset($this->readStreams[$key], $this->readListeners[$key]);
    }

    public function removeWriteStream($stream) {
        if ($stream instanceof \ZMQSocket) {
            $key = spl_object_hash($stream);
        } else {
            $key = (int)$stream;
        }
        if (isset($this->readStreams[$key])) {
            $this->zmq_poller->remove($this->readStreams[$key]);
            $poll_id = $this->zmq_poller->add($stream, \ZMQ::POLL_IN); 
            $this->readStreams[$key] = $poll_id;  
        } else {
            $this->zmq_poller->remove($this->writeStreams[$key]);
        }
        unset($this->writeStreams[$key], $this->writeListeners[$key]);
    }

    public function addTimer($interval, $callback) {
        $timer = new Timer($interval, $callback, false);
        $this->timers->add($timer);
        return $timer;
    }

    public function addPeriodicTimer($interval, $callback) {
        $timer = new Timer($interval, $callback, true);
        $this->timers->add($timer);
        return $timer;
    }

    public function cancelTimer(TimerInterface $timer) {
        $this->timers->cancel($timer);
    }

    public function futureTick($listener) {
        $this->futureTickQueue->add($listener);
    }

    public function addSignal($signal, $listener) {
        if ($this->pcntl === false) {
            throw new \BadMethodCallException('Event loop feature "signals" isn\'t supported by the "ZMQPollLoop"');
        }
        $first = $this->signals->count($signal) === 0;
        $this->signals->add($signal, $listener);
        if ($first) {
            pcntl_signal($signal, array($this->signals, 'call'));
        }
    }

    public function removeSignal($signal, $listener) {
        if (!$this->signals->count($signal)) {
            return;
        }
        $this->signals->remove($signal, $listener);
        if ($this->signals->count($signal) === 0) {
            pcntl_signal($signal, \SIG_DFL);
        }
    }

    public function run() {
        $this->running = true;
        while ($this->running) {
            $this->futureTickQueue->tick();
            $this->timers->tick();
            if (!$this->running || !$this->futureTickQueue->isEmpty()) {
                $timeout = 0;

            } elseif ($scheduledAt = $this->timers->getFirst()) {
                $timeout = $scheduledAt - $this->timers->getTime();
                if ($timeout < 0) {
                    $timeout = 0;
                } else {
                    $timeout = intval(($timeout * self::MUS));
                    $timeout = $timeout > \PHP_INT_MAX ? \PHP_INT_MAX : $timeout;
                }
            } elseif ($this->readStreams || $this->writeStreams || !$this->signals->isEmpty()) {
                $timeout = null;
            } else {
                break;
            }
            $this->waitForActivity($timeout);
        }
    }

    public function stop() {
        $this->zmq_poller->clear();
        foreach($this->writeStreams as $key => $value) {
            unset($this->writeStreams[$key], $this->writeListeners[$key]);
        }      
        foreach($this->readStreams as $key => $value) {
            unset($this->readStreams[$key], $this->readStreams[$key]
            );            
        }
        $this->running = false;
    }

    private function waitForActivity($timeout) {        
        if ($this->readStreams || $this->writeStreams) {
            $read = $write = array();
            $ret = $this->zmq_poller->poll($read, $write, $timeout === null ? -1 : $timeout);
            if ($this->pcntlPoll) {
                pcntl_signal_dispatch();
            }
            foreach ($read as $stream)  {
                if ($stream instanceof \ZMQSocket) {
                    $key = spl_object_hash($stream);
                } else {
                    $key = (int)$stream;
                }
                if (isset($this->readListeners[$key])) {
                    call_user_func($this->readListeners[$key], $stream);
                }
            }
            foreach ($write as $stream) {
                if ($stream instanceof \ZMQSocket) {
                    $key = spl_object_hash($stream);
                } else {
                    $key = (int)$stream;
                }
                if (isset($this->writeListeners[$key])) {
                    call_user_func($this->writeListeners[$key], $stream);
                }
            }
            return $ret;
        }
        if ($timeout > 0) {
            usleep($timeout);
        } elseif ($timeout === null) {
            sleep(PHP_INT_MAX);
        }        
    }
}
