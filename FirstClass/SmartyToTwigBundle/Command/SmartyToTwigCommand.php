<?php

namespace FirstClass\SmartyToTwigBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use FirstClass\SmartyToTwigExtensionInterface;

class SmartyToTwigCommand extends ContainerAwareCommand {

    protected function configure() {
        $this
                ->setName('firstclass:smarty-to-twig')
                ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'The path to scan. By default we try the src/ dir.', null)
                ->addOption('save', null, InputOption::VALUE_OPTIONAL, 'Whether we should save the file or not? Default: NO', false)
                ->addOption('debug', 'd', InputOption::VALUE_OPTIONAL, 'debug mode enabled throws an exception on the first error found.', true)
                ->setDescription('Convert smarty templates to twig.');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {

        if (!( $path = $input->getOption('path'))) {
            $path = realpath(__DIR__ . '/../../../');
        }

        $save = $input->getOption('save');

        $output->writeln('Doing a dry-run. Not saving any files.');
        sleep(1);

        $converter = new \FirstClass\SmartyToTwig($path);
        $converter->setTwigEnvironment($this->getContainer()->get('twig'));


        $extension = $this->getContainer()->get('firstclass.smartytotwig.extension');

        $plugins = $extension->getPlugins();

        foreach ($plugins as $plugin) {
            if ($plugin instanceof SmartyToTwigExtensionInterface) {
                $plugin->load($converter);
            } else {
                throw new \Symfony\Component\DependencyInjection\Exception\RuntimeException(
                'Please check that your class implements  FirstClass\SmartyToTwigExtensionInterface'
                );
            }
        }
        $converter->setDebug($input->getOption('debug'));
        $converter->process($save, $output);
    }

}
