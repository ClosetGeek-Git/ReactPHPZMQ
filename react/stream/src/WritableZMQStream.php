<?php

namespace React\Stream;

use Evenement\EventEmitter;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

final class WritableZMQStream extends EventEmitter implements WritableStreamInterface
{
    public $stream;
    public $loop;
    public $listening = false;
    public $writable = true;
    public $closed = false;
    public $max_buffer_size = 1024;
    public $data = [];

    public function __construct($stream, LoopInterface $loop = null, $writeBufferSoftLimit = null, $writeChunkSize = null) {
        if(!$stream instanceof \ZMQSocket) {
            throw new \InvalidArgumentException('First parameter must be a valid stream resource');
        }
        $this->stream = $stream;
        $this->loop = $loop ?: Loop::get();
    }
    
    public setMaxWriteBufferSize($max_buffer_size)
    {
        $this->max_buffer_size = $max_buffer_size;
    }
    
    public function isWritable() {
        return $this->writable;
    }

    public function write($data) {
        if(!$this->writable) {
            return false;
        }

        $this->data[] = $data;

        if(!$this->listening) {
            $this->listening = true;
            $this->loop->addWriteStream($this->stream, array($this, 'handleWrite'));
        }
                
        return !(count($this->data) > $this->max_buffer_size);
    }

    public function end($data = null) {
        if(null !== $data) {
            $this->write($data);
        }

        $this->writable = false;

        if($this->data === [] || count($this->data) == 0) {
            $this->close();
        }
    }

    public function close()
    {
        if($this->closed) {
            return;
        }

        if($this->listening) {
            $this->listening = false;
            $this->loop->removeWriteStream($this->stream);
        }

        $this->closed = true;
        $this->writable = false;
        $this->data = [];

        $this->emit('close');
        $this->removeAllListeners();

        $endpoints = $this->stream->getendpoints();
        
        foreach($endpoints['connect'] as $dsn) {
            $this->stream->disconnect($dsn);
        }

        foreach($endpoints['bind'] as $dsn) {
            $this->stream->unbind($dsn);
        }
    }

    public function getEndPoints() {
        return $this->stream->getendpoints();
    }
    
    public function handleWrite() {
        foreach($this->data as $key => $value) {
            if(is_array($value)) {
                try {
                    $this->stream->sendmulti($value);
                }catch(ZMQSocketException $e) {
                    $this->emit('error', array(new \RuntimeException('Unable to read from stream: ' . $e->getMessage())));
                    return;                
                }
            }else if(is_string($value)) {
                try {
                    $this->stream->send($value);
                }catch(ZMQSocketException $e) {
                    $this->emit('error', array(new \RuntimeException('Unable to read from stream: ' . $e->getMessage())));
                    return;                
                }        
            }

            unset($this->data[$key]);
            break;
        }

        if($this->listening && count($this->data) == 0) {
            $this->loop->removeWriteStream($this->stream);
            $this->listening = false;
        }
    }
}
