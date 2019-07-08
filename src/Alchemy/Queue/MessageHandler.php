<?php

namespace Alchemy\WorkerPlugin\Queue;

use Alchemy\WorkerPlugin\Worker\WorkerInvoker;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;
use PhpAmqpLib\Channel\AMQPChannel;

class MessageHandler
{
    private $messagePublisher;

    public function __construct(MessagePublisher $messagePublisher)
    {
        $this->messagePublisher = $messagePublisher;
    }

    public function consume(AMQPChannel $channel, WorkerInvoker $workerInvoker, $argQueueName)
    {
        $publisher = $this->messagePublisher;

        // define consume callbacks
        $callback = function (AMQPMessage $message) use ($channel, $workerInvoker, $publisher) {

            $data = json_decode($message->getBody(), true);

            try {
                $workerInvoker->invokeWorker($data['message_type'], json_encode($data['payload']));

                $channel->basic_ack($message->delivery_info['delivery_tag']);

                if ($data['message_type'] !==  MessagePublisher::WRITE_LOGS_TYPE) {
                    $oldPayload = $data['payload'];
                    $message = $data['message_type'].' to be consumed! >> Payload ::'. json_encode($oldPayload);

                    $publisher->pushLog($message);
                }
            } catch (\Exception $e) {
                $channel->basic_nack($message->delivery_info['delivery_tag']);
            }

        };

        foreach (AMQPConnection::$dafaultQueues as $queueName) {
            if ($argQueueName ) {
                if (in_array($queueName, $argQueueName)) {
                    $channel->basic_consume($queueName, Uuid::uuid4(), false, false, false, false, $callback);
                }
            } else {
                    $channel->basic_consume($queueName, Uuid::uuid4(), false, false, false, false, $callback);
            }
        }

    }
}
