<?php

namespace Alchemy\WorkerPlugin\Subscriber;

use Alchemy\Phrasea\Core\Event\Record\MetadataChangedEvent;
use Alchemy\Phrasea\Core\Event\Record\RecordEvent;
use Alchemy\Phrasea\Core\Event\Record\RecordEvents;
use Alchemy\Phrasea\Core\Event\Record\SubdefinitionBuildEvent;
use Alchemy\Phrasea\Databox\DataboxBoundRepositoryProvider;
use Alchemy\Phrasea\Databox\Subdef\MediaSubdefRepository;
use Alchemy\WorkerPlugin\Event\StoryCreateCoverEvent;
use Alchemy\WorkerPlugin\Event\WorkerPluginEvents;
use Alchemy\WorkerPlugin\Queue\MessagePublisher;
use Alchemy\WorkerPlugin\Worker\CreateRecordWorker;
use Alchemy\WorkerPlugin\Worker\Factory\WorkerFactoryInterface;
use Alchemy\WorkerPlugin\Worker\Resolver\TypeBasedWorkerResolver;
use Alchemy\WorkerPlugin\Worker\Resolver\WorkerResolverInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RecordSubscriber implements EventSubscriberInterface
{
    /** @var MessagePublisher $messagePublisher */
    private $messagePublisher;

    /** @var TypeBasedWorkerResolver  $workerResolver*/
    private $workerResolver;

    /** @var  DataboxBoundRepositoryProvider $databoxRepoProvider */
    private $databoxRepoProvider;

    public function __construct(
        MessagePublisher $messagePublisher,
        WorkerResolverInterface $workerResolver,
        DataboxBoundRepositoryProvider $databoxRepoProvider
    )
    {
        $this->messagePublisher    = $messagePublisher;
        $this->workerResolver      = $workerResolver;
        $this->databoxRepoProvider = $databoxRepoProvider;
    }

    public function onSubdefinitionBuild(SubdefinitionBuildEvent $event)
    {
        $payload = [
            'message_type' => MessagePublisher::SUBDEF_CREATION_TYPE,
            'payload' => [
                'recordId'  => $event->getRecord()->getRecordId(),
                'databoxId' => $event->getRecord()->getDataboxId()
            ]
        ];

        $this->messagePublisher->publishMessage($payload, MessagePublisher::SUBDEF_QUEUE);

        // avoid to execute the buildsubdef listener in the phraseanet core
        $event->stopPropagation();
    }

    public function onRecordCreated(RecordEvent $event)
    {
        $this->messagePublisher->pushLog(sprintf('The %s= %d was successfully created',
            ($event->getRecord()->isStory() ? "story story_id" : "record record_id"),
            $event->getRecord()->getRecordId()
        ));

        if (!$event->getRecord()->isStory()) {
            $payload = [
                'message_type' => MessagePublisher::SUBDEF_CREATION_TYPE,
                'payload' => [
                    'recordId'  => $event->getRecord()->getRecordId(),
                    'databoxId' => $event->getRecord()->getDataboxId(),
                    'status'    => MessagePublisher::NEW_RECORD_MESSAGE
                ]
            ];

            $this->messagePublisher->publishMessage($payload, MessagePublisher::SUBDEF_QUEUE);
        }

    }

    public function onMetadataChanged(MetadataChangedEvent $event)
    {
        $subdefs = $this->getMediaSubdefRepository($event->getRecord()->getDataboxId())->findByRecordIdsAndNames([$event->getRecord()->getRecordId()]);

        if (count($subdefs) > 1) {
            $payload = [
                'message_type' => MessagePublisher::WRITE_METADATAS_TYPE,
                'payload' => [
                    'recordId'  => $event->getRecord()->getRecordId(),
                    'databoxId' => $event->getRecord()->getDataboxId()
                ]
            ];

            $this->messagePublisher->publishMessage($payload, MessagePublisher::METADATAS_QUEUE);
        }
    }

    public function onStoryCreateCover(StoryCreateCoverEvent $event)
    {
        /** @var  WorkerFactoryInterface[] $factories */
        $factories = $this->workerResolver->getFactories();

        /** @var CreateRecordWorker $createRecordWorker */
        $createRecordWorker = $factories[MessagePublisher::CREATE_RECORD_TYPE]->createWorker();

        $createRecordWorker->setStoryCover($event->getData());
    }

    public static function getSubscribedEvents()
    {
        //  the method onBuildSubdefs listener in higher priority , so it called first and after stop event propagation$
        //  to avoid to execute phraseanet core listener

        return [
            RecordEvents::CREATED                  => 'onRecordCreated',
            RecordEvents::SUBDEFINITION_BUILD      => ['onSubdefinitionBuild', 10],
            RecordEvents::METADATA_CHANGED         => 'onMetadataChanged',
            WorkerPluginEvents::STORY_CREATE_COVER => 'onStoryCreateCover',
        ];
    }

    /**
     * @return MediaSubdefRepository
     */
    private function getMediaSubdefRepository($databoxId)
    {
        return $this->databoxRepoProvider->getRepositoryForDatabox($databoxId);
    }
}
