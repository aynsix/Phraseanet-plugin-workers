<?php

/*
 * This file is part of Phraseanet graylog plugin
 *
 * (c) 2005-2019 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\WorkerPlugin\Provider;

use Alchemy\Phrasea\Core\Configuration\PropertyAccess;

use Alchemy\Phrasea\Core\Event\Record\RecordEvents;
use Alchemy\Phrasea\Core\Event\Subscriber\ExportSubscriber as ExportMailSubscriber;
use Alchemy\Phrasea\Core\PhraseaEvents;
use Alchemy\Phrasea\Model\Manipulator\WebhookEventManipulator;
use Alchemy\Phrasea\Plugin\PluginProviderInterface;
use Alchemy\Phrasea\Application as PhraseaApplication;
use Alchemy\WorkerPlugin\Queue\AMQPConnection;
use Alchemy\WorkerPlugin\Queue\MessageHandler;
use Alchemy\WorkerPlugin\Queue\MessagePublisher;
use Alchemy\WorkerPlugin\Queue\WebhookPublisher;
use Alchemy\WorkerPlugin\Subscriber\AssetsIngestSubscriber;
use Alchemy\WorkerPlugin\Subscriber\ExportSubscriber;
use Alchemy\WorkerPlugin\Subscriber\RecordSubscriber;
use Alchemy\WorkerPlugin\Subscriber\SearchengineSubscriber;
use Alchemy\WorkerPlugin\Subscriber\WebhookSubscriber;
use Silex\Application;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class QueueServiceProvider implements PluginProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(Application $app)
    {
        $app['alchemy_service.server'] = $app->share(function (Application $app) {
            $defaultConfiguration = [
                'host'      => 'localhost',
                'port'      => 5672,
                'user'      => 'guest',
                'password'  => 'guest',
                'vhost'     => '/'
            ];

            /** @var PropertyAccess $configuration */
            $configuration = $app['conf'];

            $serverConfigurations = $configuration->get(['rabbitmq', 'server'], $defaultConfiguration);

            return $serverConfigurations;
        });

        $app['alchemy_service.amqp.connection'] = $app->share(function (Application $app) {
            return new AMQPConnection($app['alchemy_service.server']);
        });

        $app['alchemy_service.message.handler'] = $app->share(function (Application $app) {
            return new MessageHandler($app['alchemy_service.message.publisher']);
        });

        $app['alchemy_service.message.publisher'] = $app->share(function (Application $app) {
            return new MessagePublisher($app['alchemy_service.amqp.connection'], $app['alchemy_service.logger']);
        });

        $app['alchemy_service.webhook.publisher'] = $app->share(function (Application $app) {
            return new WebhookPublisher($app['alchemy_service.message.publisher']);
        });

        $app['manipulator.webhook-event'] = $app->share(function (Application $app) {
            return new WebhookEventManipulator(
                $app['orm.em'],
                $app['repo.webhook-event'],
                $app['alchemy_service.webhook.publisher']
            );
        });

        $app['dispatcher'] = $app->share(
            $app->extend('dispatcher', function (EventDispatcherInterface $dispatcher, Application $app) {

                // override  phraseanet core event
                $exportMailListener = array(new ExportMailSubscriber($app), 'onCreateExportMail');
                $deleteRecordListener = array($app['phraseanet.record-edit-subscriber'], 'onDelete');

                $dispatcher->removeListener(PhraseaEvents::EXPORT_MAIL_CREATE, $exportMailListener);
                $dispatcher->removeListener(RecordEvents::DELETE, $deleteRecordListener);

                $dispatcher->addSubscriber(
                    (new RecordSubscriber($app)
                    )->setApplicationBox($app['phraseanet.appbox'])

                );
                $dispatcher->addSubscriber(new ExportSubscriber($app['alchemy_service.message.publisher']));
                $dispatcher->addSubscriber(new AssetsIngestSubscriber($app['alchemy_service.message.publisher']));
                $dispatcher->addSubscriber(new SearchengineSubscriber($app['alchemy_service.message.publisher']));
                $dispatcher->addSubscriber(new WebhookSubscriber($app['alchemy_service.message.publisher']));

                return $dispatcher;
            })
        );

    }

    /**
     * {@inheritdoc}
     */
    public function boot(Application $app)
    {

    }

    /**
     * {@inheritdoc}
     */
    public static function create(PhraseaApplication $app)
    {
        return new static();
    }

}
