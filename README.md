SmartyToTwig
============

A symfony 2.0 bundle that allows for quick(er) migration from smarty to twig.

Note: This version works for symfony 2.0.x only. After 2.0 some of the syntaxes changed.
e.g. render to render_esi. I will work on that in future versions.

Installation
============
Start up a console and goto your src/ directory.
There clone the project.

```
git clone https://github.com/winus/SmartyToTwig.git ./
```

Register the project and you should be good to go!
```php
$bundles = array(
            ...
            new FirstClass\SmartyToTwigBundle\SmartyToTwigBundle(),
            ...
        );
```

Afterwards you get a console command 
```
app/console firstclass:smarty-to-twig
```
```
Usage:
 firstclass:smarty-to-twig [--save[="..."]] [path]

Arguments:
 path    The path to scan. By default we try the src/ dir.

Options:
 --save  Whether we should save the file or not? Default: NO (default: false)
```

It is also possible to plugin your own converters. For your own function's or modifiers you have created.

In your projects DepencyInjection/YourprojectExtension.php just add the following:

```php
 public function load(array $configs, ContainerBuilder $container) {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);


        $container->register('firstclass.smartytotwig.extension.projectspecific', 'YourProject\SmartyToTwig')
                ->addTag('firstclass.smartytotwig.plugin');
        
        $loader = new Loader\PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');
    }
```

And define your own converter class.
Make sure it implements the ```\FirstClass\SmartyToTwigExtensionInterface``` Interface otherwise it will throw an exception.

The converter is given as an parameter to the load function, and you plug your own implementation in there.
After that you are free to mess around with the ```$content``` variable.

```php
namespace YourProject;

class SmartyToTwig implements \FirstClass\SmartyToTwigExtensionInterface{
    public function load(\FirstClass\SmartyToTwig $converter) {
        
        $converter->plugin(function($content) {
                    //do something
                    return $content;
                });
    }
}
```

The ```$converter->plugin()``` also takes an optional second parameter. A priority. The default priority is 100.
With means it runs after all the default smarty tags are converted. 

Priority 1 means it will run before the default conversions. Those are the only 2 for now.

```php
$converter->plugin(function($content) {
                    //do something
                    return $content;
                }, 1); // runs before the default conversions.
```
