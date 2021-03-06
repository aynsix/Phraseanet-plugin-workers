<?php

namespace Alchemy\WorkerPlugin\Command;

use Alchemy\Phrasea\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class WorkerShowConfigCommand extends Command
{
    public function __construct()
    {
        parent::__construct('worker:show-configuration');

        $this->setDescription('Show queues configuration');
    }

    public function doExecute(InputInterface $input, OutputInterface $output)
    {
        $serverConfiguration = $this->container['alchemy_service.server'];

        $output->writeln([ '', 'Configured server: ' ]);

        $output->writeln([ 'Rabbit Server : ' . Yaml::dump($serverConfiguration, 0), '' ]);
    }
}
