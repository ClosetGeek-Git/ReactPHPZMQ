<?php

/**
 * FIRST NOTES WHEN DEBUGGING:
 * 
 * NOT SURE IF READS/WRITES SHOULD BE FAIR. THIS PRESUMES THE POLL IS HANDLING ZMQ QUEUES AS WELL AS
 * SOCKETS.
 * 
 * FOR EXAMPLE, in WritableZMQStream\handleWrite()
 * 
 *
 *   public function handleWrite()
 *   {
 *       foreach($this->data as $key => $value)
 *       {
 *           ...
 *
 *           unset($this->data[$key]);
 *           // THIS MAY BE WRONG.... but:
 *           // Not edge triggered so no need to do this in a loop. Break and return when poll signals.
 *           break;
 *       }
 *   }

 * 
 * NOT 100% SURE ABOUT THE FOLLOWING LINES:
 *  return ($this->writable && ($this->stream->getSockOpt(ZMQ::SOCKOPT_EVENTS) & ZMQ::POLL_OUT)); 
 *   and
 *  return ($this->readable && ($this->stream->getSockOpt(ZMQ::SOCKOPT_EVENTS) & ZMQ::POLL_IN));
 * 
 * IN WritableZMQStream:
 *  NOT SURE ABOUT
 *    return ($this->writable && ($this->stream->getSockOpt(ZMQ::SOCKOPT_EVENTS) & ZMQ::POLL_OUT));
 * 
 * Additionally, in write() method changed
 * 
 *   public function write($data)
 *   {
 *       if (!$this->writable)
 * 
 *  INTO
 * 
 *   public function write($data)
 *   {
 *       if (!$this->isWritable())
 *
 *  NOT SURE IF THIS WILL WORK AS EXPECTED
 * 
 */

namespace React\Socket;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise;
use InvalidArgumentException;
use RuntimeException;

final class ZMQConnector implements ConnectorInterface
{
    private $loop;
    private $config;
    private $context = null;
    private $zmq_socket_type = null;
    private $zmq_socket = null;
    private $bound = false;

    public function __construct(LoopInterface $loop = null, $config = null)
    {
        $this->loop = $loop ?: Loop::get();
        
        if($config == null || !is_array($config))
        {
            throw new InvalidArgumentException('Must provide a valid configuration in second argument');
        }

        if(!isset($config['type']))
        {
            throw new InvalidArgumentException('Cannot create ZMQConnector without a zmq socket type');
        
        }else
        {
            switch($config['type'])
            {
                case \ZMQ::SOCKET_PAIR:
                case \ZMQ::SOCKET_PUB:
                case \ZMQ::SOCKET_SUB:
                case \ZMQ::SOCKET_REQ:
                case \ZMQ::SOCKET_REP:
                case \ZMQ::SOCKET_XREQ:
                case \ZMQ::SOCKET_XREP:
                case \ZMQ::SOCKET_PUSH:
                case \ZMQ::SOCKET_PULL:
                case \ZMQ::SOCKET_DEALER:
                case \ZMQ::SOCKET_ROUTER:
                case \ZMQ::SOCKET_XSUB:
                case \ZMQ::SOCKET_XPUB:
                case \ZMQ::SOCKET_STREAM:
                case \ZMQ::SOCKET_UPSTREAM:
                case \ZMQ::SOCKET_DOWNSTREAM:
                {
                    $this->zmq_socket_type = $config['type'];
                }
                break;
                default:
                {
                    throw new InvalidArgumentException('Not a valid ZMQ Socket type');    
                }
            }
        }

        if (isset($config['context']))
        {
            if ($config['context'] instanceof \ZMQContext)
            {
                $this->context = $config['context'];
            
            } else
            {
                throw new InvalidArgumentException('\'context\' is set in configuration argument but is not a valid ZMQContext');
            }
        
        } else
        {
            $this->context = \ZMQContext::acquire();
        }

        $this->zmq_socket = new \ZMQSocket($this->context, $this->zmq_socket_type);

        $this->config = $config;
        
    }

    public function bind($path)
    {
        try
        {
            $resource = $this->zmq_socket->bind($path);
        } catch(Exception $e)
        {
            return Promise\reject(new \RuntimeException("Unable to bind ZMQ socket: ". $e->getMessage() ));            
        }        

        $connection = new ZMQConnection($resource, $this->loop);
        $connection->resource_id = spl_object_hash($resource);;

        return Promise\resolve($connection);
    }

    public function connect($path)
    {   
        $resource = NULL;
        try
        {
            $resource = $this->zmq_socket->connect($path);
        
        } catch(Exception $e)
        {
            return Promise\reject(new \RuntimeException("Unable to connect ZMQ socket: ". $e->getMessage() ));            
        }        

        $connection = new ZMQConnection($resource, $this->loop);
        $connection->resource_id = spl_object_hash($resource);;

        return Promise\resolve($connection);
    }
}
