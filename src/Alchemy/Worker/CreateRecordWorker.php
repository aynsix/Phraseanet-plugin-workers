<?php

namespace Alchemy\WorkerPlugin\Worker;

use Alchemy\Phrasea\Application\Helper\ApplicationBoxAware;
use Alchemy\Phrasea\Application\Helper\EntityManagerAware;
use Alchemy\Phrasea\Application\Helper\BorderManagerAware;
use Alchemy\Phrasea\Application\Helper\DispatcherAware;
use Alchemy\Phrasea\Application\Helper\FilesystemAware;
use Alchemy\Phrasea\Application as PhraseaApplication;
use Alchemy\Phrasea\Border\Attribute\MetaField;
use Alchemy\Phrasea\Border\Attribute\Status;
use Alchemy\Phrasea\Border\File;
use Alchemy\Phrasea\Border\Visa;
use Alchemy\Phrasea\Core\Event\LazaretEvent;
use Alchemy\Phrasea\Core\Event\Record\RecordEvents;
use Alchemy\Phrasea\Core\Event\Record\StoryCoverChangedEvent;
use Alchemy\Phrasea\Core\Event\RecordEdit;
use Alchemy\Phrasea\Core\PhraseaEvents;
use Alchemy\Phrasea\Media\SubdefSubstituer;
use Alchemy\Phrasea\Model\Entities\LazaretFile;
use Alchemy\Phrasea\Model\Entities\LazaretSession;
use Alchemy\Phrasea\Model\Entities\User;
use Alchemy\Phrasea\Model\Repositories\UserRepository;
use Alchemy\WorkerPlugin\Queue\MessagePublisher;
use GuzzleHttp\Client;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CreateRecordWorker implements WorkerInterface
{
    use ApplicationBoxAware;
    use EntityManagerAware;
    use BorderManagerAware;
    use DispatcherAware;
    use FilesystemAware;

    private $app;
    private $logger;

    /** @var MessagePublisher $messagePublisher */
    private $messagePublisher;

    public function __construct(PhraseaApplication $app)
    {
        $this->app              = $app;
        $this->logger           = $this->app['alchemy_service.logger'];
        $this->messagePublisher = $this->app['alchemy_service.message.publisher'];
    }

    public function process(array $payload)
    {
        $uploaderConfig = $this->app['worker_plugin.config']['worker_plugin'];

        $uploaderClient = new Client(['base_uri' => $uploaderConfig['url_uploader_service']]);


        //get asset informations
        $body = $uploaderClient->get('/assets/'.$payload['asset'], [
            'headers' => [
                'Authorization' => 'AssetToken '.$payload['assetToken']
            ]
        ])->getBody()->getContents();

        $body = json_decode($body,true);

        $tempfile = $this->getTemporaryFilesystem()->createTemporaryFile('download_', null, pathinfo($body['originalName'], PATHINFO_EXTENSION));

        //download the asset
        $res = $uploaderClient->get('/assets/'.$payload['asset'].'/download', [
            'headers' => [
                'Authorization' => 'AssetToken '.$payload['assetToken']
            ],
            'save_to' => $tempfile
        ]);

        if ($res->getStatusCode() !== 200) {
            $this->logger->error(sprintf('Error %s downloading "%s"', $res->getStatusCode(), $uploaderConfig['url_uploader_service'].'/assets/'.$payload['asset'].'/download'));
        }


        $lazaretSession = new LazaretSession();

        $userRepository = $this->getUserRepository();
        $user = null;

        if (!empty($body['formData']['phraseanet_submiter_email'])) {
            $user = $userRepository->findByEmail($body['formData']['phraseanet_submiter_email']);
        }

        if ($user === null && !empty($body['formData']['phraseanet_user_submiter_id'])) {
            $user = $userRepository->find($body['formData']['phraseanet_user_submiter_id']);
        }

        if ($user !== null) {
            $lazaretSession->setUser($user);
        }

        $this->getEntityManager()->persist($lazaretSession);


        $renamedFilename = $tempfile;
        $media = $this->app->getMediaFromUri($renamedFilename);

        if (!isset($body['formData']['collection_destination'])) {
            $this->messagePublisher->pushLog("The collection_destination is not defined");

            return ;
        }

        $base_id = $body['formData']['collection_destination'];
        $collection = \collection::getByBaseId($this->app, $base_id);
        $sbasId = $collection->get_sbas_id();

        $packageFile = new File($this->app, $media, $collection, $body['originalName']);

        // get metadata and status
        $statusbit = null;
        foreach ($body['formData'] as $key => $value) {
            if (strstr($key, 'metadata')) {
                $tMeta = explode('-', $key);

                $metaField = $collection->get_databox()->get_meta_structure()->get_element($tMeta[1]);

                $packageFile->addAttribute(new MetaField($metaField, [$value]));
            }

            if (strstr($key, 'statusbit')) {
                $tStatus = explode('-', $key);
                $statusbit[$tStatus[1]] = $value;
            }
        }

        if (!is_null($statusbit)) {
            $status = '';
            foreach (range(0, 31) as $i) {
                $status .= isset($statusbit[$i]) ? ($statusbit[$i] ? '1' : '0') : '0';
            }
            $packageFile->addAttribute(new Status($this->app, strrev($status)));
        }

        $reasons = [];
        $elementCreated = null;

        $callback = function ($element, Visa $visa) use (&$reasons, &$elementCreated) {
            foreach ($visa->getResponses() as $response) {
                if (!$response->isOk()) {
                    $reasons[] = $response->getMessage($this->app['translator']);
                }
            }

            $elementCreated = $element;
        };

        $this->getBorderManager()->process($lazaretSession, $packageFile, $callback);

        if ($elementCreated instanceof \record_adapter) {
            $this->dispatch(PhraseaEvents::RECORD_UPLOAD, new RecordEdit($elementCreated));
        } else {
            $this->messagePublisher->pushLog(sprintf('The file was moved to the quarantine: %s', json_encode($reasons)));
            /** @var LazaretFile $elementCreated */
            $this->dispatch(PhraseaEvents::LAZARET_CREATE, new LazaretEvent($elementCreated));
        }

        // add record in a story if story is defined

        if (is_int($payload['storyId']) && $elementCreated instanceof \record_adapter) {
            $this->addRecordInStory($user, $elementCreated, $sbasId, $payload['storyId']);
        }

    }

    /**
     * @param string $data  databoxId_storyId_recordId
     */
    public function setStoryCover($data)
    {
        // get databoxId , storyId and recordId
        $tData = explode('_', $data);

        $record = $this->findDataboxById($tData[0])->get_record($tData[2]);

        $story =  $this->findDataboxById($tData[0])->get_record( $tData[1]);

        $subdefChanged = false;
        foreach ($record->get_subdefs() as $name => $value) {
            if (!in_array($name, array('thumbnail', 'preview'))) {
                continue;
            }

            $media = $this->app->getMediaFromUri($value->getRealPath());
            $this->getSubdefSubstituer()->substituteSubdef($story, $name, $media);  // name = thumbnail | preview
            $subdefChanged = true;
        }

        if ($subdefChanged) {
            $this->dispatch(RecordEvents::STORY_COVER_CHANGED, new StoryCoverChangedEvent($story, $record));
            $this->dispatch(PhraseaEvents::RECORD_EDIT, new RecordEdit($story));

            $this->messagePublisher->pushLog(sprintf("Cover set for story story_id= %d with the record record_id = %d", $story->getRecordId(), $record->getRecordId()));
        }
    }

    /**
     * @param $user
     * @param \record_adapter $elementCreated
     * @param $sbasId
     * @param $storyId
     */
    private function addRecordInStory($user, $elementCreated, $sbasId, $storyId)
    {
        $story = new \record_adapter($this->app, $sbasId, $storyId);

        if (!$this->getAclForUser($user)->has_right_on_base($story->getBaseId(), \ACL::CANMODIFRECORD)) {
            $this->messagePublisher->pushLog(sprintf("The user %s can not add document to the story story_id = %d", $user->getLogin(), $story->getRecordId()));

            throw new AccessDeniedHttpException('You can not add document to this Story');
        }

        if (!$story->hasChild($elementCreated)) {
            $story->appendChild($elementCreated);

            if (SubdefCreationWorker::checkIfFirstChild($story, $elementCreated)) {
                // add a title to the story
                $metadatas = [];

                foreach ($story->getDatabox()->get_meta_structure() as $meta) {
                    if ($meta->get_thumbtitle()) {
                        $value = $elementCreated->getTitle();
                    } else {
                        continue;
                    }

                    $metadatas[] = [
                        'meta_struct_id' => $meta->get_id(),
                        'meta_id'        => null,
                        'value'          => $value,
                    ];

                    break;
                }

                $story->set_metadatas($metadatas)->rebuild_subdefs();

                $data = implode('_', [$story->getDataboxId(), $storyId, $elementCreated->getRecordId()]);
            }

            $this->messagePublisher->pushLog(sprintf('The record record_id= %d was successfully added in the story record_id= %d', $elementCreated->getRecordId(), $story->getRecordId()));
            $this->dispatch(PhraseaEvents::RECORD_EDIT, new RecordEdit($story));
        }
    }

    /**
     * @return UserRepository
     */
    private function getUserRepository()
    {
        return $this->app['repo.users'];
    }

    /**
     * @param User $user
     * @return \ACL
     */
    private function getAclForUser(User $user)
    {
        $aclProvider = $this->app['acl'];

        return $aclProvider->get($user);
    }

    /**
     * @return SubdefSubstituer
     */
    private function getSubdefSubstituer()
    {
        return $this->app['subdef.substituer'];
    }
}
