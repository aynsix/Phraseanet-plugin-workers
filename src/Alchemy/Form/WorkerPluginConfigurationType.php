<?php

namespace Alchemy\WorkerPlugin\Form;

use Alchemy\WorkerPlugin\Queue\MessagePublisher;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

class WorkerPluginConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        parent::buildForm($builder, $options);

        $builder
            ->add(MessagePublisher::ASSETS_INGEST_TYPE, 'text', [
                'label' => 'Ingest retry delay in ms'
            ])
            ->add(MessagePublisher::CREATE_RECORD_TYPE, 'text', [
                'label' => 'Create record retry delay in ms'
            ])
            ->add(MessagePublisher::SUBDEF_CREATION_TYPE, 'text', [
                'label' => 'Subdefinition retry delay in ms'
            ])
            ->add(MessagePublisher::WRITE_METADATAS_TYPE, 'text', [
                'label' => 'Metadatas retry delay in ms'
            ])
            ->add(MessagePublisher::WEBHOOK_TYPE, 'text', [
                'label' => 'Webhook retry delay in ms'
            ])
            ->add(MessagePublisher::EXPORT_MAIL_TYPE, 'text', [
                'label' => 'Export mail retry delay in ms'
            ])
            ->add(MessagePublisher::POPULATE_INDEX_TYPE, 'text', [
                'label' => 'Populate Index retry delay in ms'
            ])
        ;
    }

    public function getName()
    {
        return 'worker_plugin_configuration';
    }
}
