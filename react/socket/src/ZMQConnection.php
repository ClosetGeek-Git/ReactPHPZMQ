<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Stream\DuplexZMQStream;
use React\Stream\Util;
use React\Stream\WritableZMQStream;
use React\Stream\WritableStreamInterface;

class ZMQConnection extends EventEmitter implements ConnectionInterface
{
    public $resource_id = null;

    public $stream;

    public $input;

    public function __construct($resource, LoopInterface $loop)
    {

        $this->input = new DuplexZMQStream(
            $resource,
            $loop,
            null,
            new WritableZMQStream($resource, $loop, null, null)
        );

        $this->stream = $resource;

        Util::forwardEvents($this->input, $this, array('data', 'end', 'error', 'close', 'pipe', 'drain'));

        $this->input->on('close', array($this, 'close'));
    }

    public function isReadable()
    {
        return $this->input->isReadable();
    }

    public function isWritable()
    {
        return $this->input->isWritable();
    }

    public function pause()
    {
        $this->input->pause();
    }

    public function resume()
    {
        $this->input->resume();
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        return $this->input->pipe($dest, $options);
    }

    public function write($data)
    {
        return $this->input->write($data);
    }

    public function end($data = null)
    {
        $this->input->end($data);
    }

    public function close()
    {
        $this->input->close();
        $this->handleClose();
        $this->removeAllListeners();
    }

    public function handleClose()
    {
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

    public function getRemoteAddress()
    {
        return $this->stream->getendpoints();
    }

    public function getLocalAddress()
    {
        return $this->stream->getendpoints();
    }


    public function getEndPoints()
    {
        return $this->stream->getendpoints();
    }
}
