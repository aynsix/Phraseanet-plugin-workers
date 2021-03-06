<?php

namespace Alchemy\WorkerPlugin\Worker;

use Alchemy\Phrasea\Application\Helper\ApplicationBoxAware;
use Alchemy\Phrasea\Core\PhraseaTokens;
use Alchemy\Phrasea\Filesystem\FilesystemService;
use Alchemy\Phrasea\Media\SubdefGenerator;
use Alchemy\WorkerPlugin\Event\StoryCreateCoverEvent;
use Alchemy\WorkerPlugin\Event\SubdefinitionCreationFailureEvent;
use Alchemy\WorkerPlugin\Event\SubdefinitionWritemetaEvent;
use Alchemy\WorkerPlugin\Event\WorkerPluginEvents;
use Alchemy\WorkerPlugin\Queue\MessagePublisher;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class SubdefCreationWorker implements WorkerInterface
{
    use ApplicationBoxAware;

    private $subdefGenerator;

    /** @var MessagePublisher $messagePublisher */
    private $messagePublisher;

    private $logger;
    private $dispatcher;
    private $filesystem;

    public function __construct(
        SubdefGenerator $subdefGenerator,
        MessagePublisher $messagePublisher,
        LoggerInterface $logger,
        EventDispatcherInterface $dispatcher,
        FilesystemService $filesystem
    )
    {
        $this->subdefGenerator  = $subdefGenerator;
        $this->messagePublisher = $messagePublisher;
        $this->logger           = $logger;
        $this->dispatcher       = $dispatcher;
        $this->filesystem       = $filesystem;
    }

    public function process(array $payload)
    {
        if(isset($payload['recordId']) && isset($payload['databoxId'])) {
            $recordId       = $payload['recordId'];
            $databoxId      = $payload['databoxId'];
            $wantedSubdef   = [$payload['subdefName']];

            $record = $this->findDataboxById($databoxId)->get_record($recordId);

            $oldLogger = $this->subdefGenerator->getLogger();

            if (!$record->isStory()) {
                $this->subdefGenerator->setLogger($this->logger);

                $this->subdefGenerator->generateSubdefs($record, $wantedSubdef);

                // begin to check if the subdef is successfully generated
                $subdef = $record->getDatabox()->get_subdef_structure()->getSubdefGroup($record->getType())->getSubdef($payload['subdefName']);
                $filePathToCheck = null;

                if ($record->has_subdef($payload['subdefName']) ) {
                    $filePathToCheck = $record->get_subdef($payload['subdefName'])->getRealPath();
                }

                $filePathToCheck = $this->filesystem->generateSubdefPathname($record, $subdef, $filePathToCheck);

                if (!$this->filesystem->exists($filePathToCheck)) {

                    $count = isset($payload['count']) ? $payload['count'] + 1 : 2 ;

                    $this->dispatcher->dispatch(WorkerPluginEvents::SUBDEFINITION_CREATION_FAILURE, new SubdefinitionCreationFailureEvent(
                        $record,
                        $payload['subdefName'],
                        'Subdef generation failed !',
                        $count
                    ));

                    $this->subdefGenerator->setLogger($oldLogger);
                    return ;
                }
                // checking ended

                // order to write meta for the subdef if needed
                $this->dispatcher->dispatch(WorkerPluginEvents::SUBDEFINITION_WRITE_META, new SubdefinitionWritemetaEvent($record, $payload['subdefName']));

                $this->subdefGenerator->setLogger($oldLogger);

                //  update jeton when subdef is created
                $this->updateJeton($record);

                $parents = $record->get_grouping_parents();

                //  create a cover for a story
                //  used when uploaded via uploader-service and grouped as a story
                if (!$parents->is_empty() && isset($payload['status']) && $payload['status'] == MessagePublisher::NEW_RECORD_MESSAGE  && in_array($payload['subdefName'], array('thumbnail', 'preview'))) {
                    foreach ($parents->get_elements() as $story) {
                        if (self::checkIfFirstChild($story, $record)) {
                            $data = implode('_', [$databoxId, $story->getRecordId(), $recordId, $payload['subdefName']]);

                            $this->dispatcher->dispatch(WorkerPluginEvents::STORY_CREATE_COVER, new StoryCreateCoverEvent($data));
                        }
                    }
                }
            }
        }
    }

    public static function checkIfFirstChild(\record_adapter $story, \record_adapter $record)
    {
        $sql = "SELECT * FROM regroup WHERE rid_parent = :parent_record_id AND rid_child = :children_id and ord = :ord";

        $connection = $record->getDatabox()->get_connection();

        $stmt = $connection->prepare($sql);

        $stmt->execute([
            ':parent_record_id' => $story->getRecordId(),
            ':children_id'      => $record->getRecordId(),
            ':ord'              => 0,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $stmt->closeCursor();

        if ($row) {
            return true;
        }

        return false;
    }

    private function updateJeton(\record_adapter $record)
    {
        $connection = $record->getDatabox()->get_connection();
        $connection->beginTransaction();

        // mark subdef created
        $sql = 'UPDATE record'
            . ' SET jeton=(jeton & ~(:token)), moddate=NOW()'
            . ' WHERE record_id=:record_id';

        $stmt = $connection->prepare($sql);

        $stmt->execute([
            ':record_id'    => $record->getRecordId(),
            ':token'        => PhraseaTokens::MAKE_SUBDEF,
        ]);

        $connection->commit();
        $stmt->closeCursor();
    }
}
