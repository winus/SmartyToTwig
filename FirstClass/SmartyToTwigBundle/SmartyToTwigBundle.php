<?php

namespace FirstClass\SmartyToTwigBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use FirstClass\SmartyToTwigBundle\DependencyInjection\Compiler\SmartyToTwigCompilerPass;

class SmartyToTwigBundle extends Bundle {

    public function build(ContainerBuilder $container) {
        parent::build($container);

        $container->addCompilerPass(new SmartyToTwigCompilerPass());
    }

}
