<?php
namespace FirstClass;

class SmartyToTwigExtensions{
    private $plugins = array();
    
    public function addPlugin($plugin){
        $this->plugins[] = $plugin;
    }
    
    public function getPlugins(){
        return $this->plugins;
    }
}