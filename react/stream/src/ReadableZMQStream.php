<?php

namespace React\Stream;

use Evenement\EventEmitter;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use InvalidArgumentException;

final class ReadableZMQStream extends EventEmitter implements ReadableStreamInterface
{
    public $stream;

    public $loop;

    public $data = [];
    public $rcvmore = false;

    public $closed = false;
    public $listening = false;

    public function __construct($stream, LoopInterface $loop = null, $readChunkSize = null)
    {
        if (!$stream instanceof \ZMQSocket) 
        {
             throw new InvalidArgumentException('First parameter must be a valid stream resource');
        }

        $this->stream = $stream;
        $this->loop = $loop ?: Loop::get();

        $this->resume();
    }

    public function isReadable()
    {
        return !$this->closed; 
    }

    public function pause()
    {
        if ($this->listening)
        {
            $this->loop->removeReadStream($this->stream);
            $this->listening = false;
        }
    }

    public function resume()
    {
        if (!$this->listening && !$this->closed)
        {
            $this->loop->addReadStream($this->stream, array($this, 'handleData'));
            $this->listening = true;
        }
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        return Util::pipe($this, $dest, $options);
    }

    public function close()
    {
        if ($this->closed)
        {
            return;
        }

        $this->closed = true;

        $this->emit('close');
        $this->pause();
        $this->removeAllListeners();

        $endpoints = $this->stream->getendpoints();
        
        foreach ($endpoints['connect'] as $dsn)
        {
            $this->stream->disconnect($dsn);
        }

        foreach ($endpoints['bind'] as $dsn)
        {
            $this->stream->unbind($dsn);
        }
    }

    public function handleData()
    {
        try 
        {
            $this->data[] = $this->stream->recvmulti();
       
        } catch (ZMQSocketException $e)
        {
           $this->emit('error', array(new \RuntimeException('Unable to read from stream: ' . $e->getMessage())));
           return;                
        }

       $this->rcvmore = $this->stream->getSockOpt(\ZMQ::SOCKOPT_RCVMORE);

       if ($this->rcvmore == 0 && count($data) > 0)
       {
           $this->emit('data', array($this->data));
           $this->data = [];
       }
    }

    public function getEndPoints()
    {
        return $this->stream->getendpoints();
    }

    private function isLegacyPipe($resource)
    {
        return false;
    }
}
