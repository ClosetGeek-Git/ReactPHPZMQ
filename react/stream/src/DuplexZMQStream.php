<?php

namespace React\Stream;

use Evenement\EventEmitter;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use InvalidArgumentException;

final class DuplexZMQStream extends EventEmitter implements DuplexStreamInterface
{
    public $stream;
    public $loop;
    public $buffer;
    public $data = [];
    public $rcvmore = false;
    public $readable = true;
    public $writable = true;
    public $closing = false;
    public $listening = false;

    public function __construct($stream, LoopInterface $loop = null, $readChunkSize = null, WritableStreamInterface $buffer = null) {
        if(!$stream instanceof \ZMQSocket) {
             throw new InvalidArgumentException('First parameter must be a valid ZMQSocket');
        }

        if($buffer === null) {
            $buffer = new WritableZMQStream($stream, $loop);
        }

        $this->stream = $stream;
        $this->loop = $loop ?: Loop::get();

        $this->buffer = $buffer;

        $that = $this;

        $this->buffer->on('error', function ($error) use ($that) {
            $that->emit('error', array($error));
        });

        $this->buffer->on('close', array($this, 'close'));

        $this->buffer->on('drain', function () use ($that) {
            $that->emit('drain');
        });
        
        $this->resume();
    }

    public function isReadable() {
        return $this->readable; 
    }

    public function isWritable() {
        return $this->writable; 
    }

    public function pause() {
        if($this->listening) {
            $this->loop->removeReadStream($this->stream);
            $this->listening = false;
        }
    }

    public function resume() {
        if(!$this->listening && $this->readable) {
            $this->loop->addReadStream($this->stream, array($this, 'handleData'));
            $this->listening = true;
        }
    }

    public function write($data) {
        if(!$this->writable) {
            return false;
        }
        return $this->buffer->write($data);
    }

    public function close() {
        if(!$this->writable && !$this->closing) {
            return;
        }

        $this->closing = false;
        $this->readable = false;
        $this->writable = false;

        $this->emit('close');
        $this->pause();
        $this->buffer->close();
        $this->removeAllListeners();

        $endpoints = $this->stream->getendpoints();
        
        foreach($endpoints['connect'] as $dsn) {
            $this->stream->disconnect($dsn);
        }
        foreach($endpoints['bind'] as $dsn) {
            $this->stream->unbind($dsn);
        }
    }

    public function end($data = null) {
        if(!$this->writable) {
            return;
        }
        $this->closing = true;
        $this->readable = false;
        $this->writable = false;
        $this->pause();
        $this->buffer->end($data);
    }

    public function pipe(WritableStreamInterface $dest, array $options = array()) {
        return Util::pipe($this, $dest, $options);
    }

    public function handleData($stream) {

        try {
            $ret = $this->stream->recvmulti();
            if(count($ret) == 1)
                $ret = $ret[0];
            $this->data[] = $ret;
        }catch(ZMQSocketException $e) {
            $this->emit('error', array(new \RuntimeException('Unable to read from stream: ' . $e->getMessage())));
            return;                
        }

        $this->rcvmore = $this->stream->getSockOpt(\ZMQ::SOCKOPT_RCVMORE);

        if ($this->rcvmore == 0 && count($this->data) > 0) {
            $this->emit('data', array($this->data));
            $this->data = [];
        }
    }

    public function getEndPoints() {
        return $this->stream->getendpoints();
    }

    private function isLegacyPipe($resource) {
        return false;
    }
}
