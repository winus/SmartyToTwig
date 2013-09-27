<?php

namespace FirstClass\SmartyToTwigBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Reference;

class SmartyToTwigCompilerPass implements CompilerPassInterface {

    public function process(ContainerBuilder $container) {
        if (!$container->hasDefinition('firstclass.smartytotwig.extension')) {
            return;
        }

        $definition = $container->getDefinition(
                'firstclass.smartytotwig.extension'
        );

        $taggedServices = $container->findTaggedServiceIds(
                'firstclass.smartytotwig.plugin'
        );
        
        
        foreach ($taggedServices as $id => $attributes) {
            $definition->addMethodCall(
                    'addPlugin', array(new Reference($id))
            );
        }
    }

}