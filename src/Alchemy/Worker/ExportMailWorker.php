<?php

namespace Alchemy\WorkerPlugin\Worker;

use Alchemy\Phrasea\Application;
use Alchemy\Phrasea\Core\Event\ExportFailureEvent;
use Alchemy\Phrasea\Core\PhraseaEvents;
use Alchemy\Phrasea\Exception\InvalidArgumentException;
use Alchemy\Phrasea\Model\Entities\Token;
use Alchemy\Phrasea\Model\Repositories\TokenRepository;
use Alchemy\Phrasea\Model\Repositories\UserRepository;
use Alchemy\Phrasea\Notification\Emitter;
use Alchemy\Phrasea\Notification\Mail\MailRecordsExport;
use Alchemy\Phrasea\Notification\Receiver;
use Alchemy\WorkerPlugin\Event\ExportMailFailureEvent;
use Alchemy\WorkerPlugin\Event\WorkerPluginEvents;

class ExportMailWorker implements WorkerInterface
{
    use Application\Helper\NotifierAware;

    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function process(array $payload)
    {
        $destMails = unserialize($payload['destinationMails']);

        $params = unserialize($payload['params']);

        /** @var UserRepository $userRepository */
        $userRepository = $this->app['repo.users'];

        $user = $userRepository->find($payload['emitterUserId']);

        /** @var TokenRepository $tokenRepository */
        $tokenRepository = $this->app['repo.tokens'];

        /** @var Token $token */
        $token = $tokenRepository->findValidToken($payload['tokenValue']);

        $list = unserialize($token->getData());

        //zip documents
        \set_export::build_zip(
            $this->app,
            $token,
            $list,
            $this->app['tmp.download.path'].'/'. $token->getValue() . '.zip'
        );

        $remaingEmails = $destMails;

        $emitter = new Emitter($user->getDisplayName(), $user->getEmail());

        foreach ($destMails as $key => $mail) {
            try {
                $receiver = new Receiver(null, trim($mail));
            } catch (InvalidArgumentException $e) {
                continue;
            }

            $mail = MailRecordsExport::create($this->app, $receiver, $emitter, $params['textmail']);
            $mail->setButtonUrl($params['url']);
            $mail->setExpiration($token->getExpiration());

            $this->deliver($mail, $params['reading_confirm']);
            unset($remaingEmails[$key]);
        }

        //some mails failed
        if (count($remaingEmails) > 0) {
            $count = isset($payload['count']) ? $payload['count'] + 1 : 2 ;

            //  notify to send to the retry queue
            $this->app['dispatcher']->dispatch(WorkerPluginEvents::EXPORT_MAIL_FAILURE, new ExportMailFailureEvent(
                $payload['emitterUserId'],
                $payload['tokenValue'],
                $remaingEmails,
                $payload['params'],
                'some mails failed',
                $count
            ));

            foreach ($remaingEmails as $mail) {
                $this->app['dispatcher']->dispatch(PhraseaEvents::EXPORT_MAIL_FAILURE, new ExportFailureEvent(
                        $user,
                        $params['ssttid'],
                        $params['lst'],
                        \eventsmanager_notify_downloadmailfail::MAIL_FAIL,
                        $mail
                    )
                );
            }
        }

    }
}
