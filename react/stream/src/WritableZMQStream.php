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
    public $data = [];

    public function __construct($stream, LoopInterface $loop = null, $writeBufferSoftLimit = null, $writeChunkSize = null)
    {
        if (!$stream instanceof \ZMQSocket)
        {
            throw new \InvalidArgumentException('First parameter must be a valid stream resource');
        }

        $this->stream = $stream;
        $this->loop = $loop ?: Loop::get();
    }

    public function isWritable()
    {
        return ($this->writable && ($this->stream->getSockOpt(\ZMQ::SOCKOPT_EVENTS) & \ZMQ::POLL_OUT));
    }

    public function write($data)
    {
        if (!$this->isWritable())
        {
            return false;
        }

        $this->data[] = $data;

        if (!$this->listening)
        {
            $this->listening = true;

            $this->loop->addWriteStream($this->stream, array($this, 'handleWrite'));
        }
                
        return !(count($this->data) > 1000);
    }

    public function end($data = null)
    {
        if (null !== $data)
        {
            $this->write($data);
        }

        $this->writable = false;

        if ($this->data === [] || count($this->data) == 0)
        {
            $this->close();
        }
    }

    public function close()
    {
        if ($this->closed)
        {
            return;
        }

        if ($this->listening)
        {
            $this->listening = false;
            $this->loop->removeWriteStream($this->stream);
        }

        $this->closed = true;
        $this->writable = false;
        $this->data = [];

        $this->emit('close');
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

    public function getEndPoints()
    {
        return $this->stream->getendpoints();
    }
    
    public function handleWrite()
    {
        foreach($this->data as $key => $value)
        {
            if (is_array($value))
            {
                try
                {
                    $this->stream->sendmulti($value);
                
                } catch (ZMQSocketException $e)
                {
                    $this->emit('error', array(new \RuntimeException('Unable to read from stream: ' . $e->getMessage())));
                    return;                
                }
            } else if(is_string($value))
            {
                try
                {
                    $this->stream->send($value);

                } catch (ZMQSocketException $e)
                {
                    $this->emit('error', array(new \RuntimeException('Unable to read from stream: ' . $e->getMessage())));
                    return;                
                }        
            }

            unset($this->data[$key]);
            break;
        }
        // stop waiting for resource to be writable
        if ($this->listening && count($this->data) == 0) {
            $this->loop->removeWriteStream($this->stream);
            $this->listening = false;
        }
    }
}
