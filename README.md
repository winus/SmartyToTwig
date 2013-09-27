SmartyToTwig
============

A symfony 2.0 bundle that allows for quick(er) migration from smarty to twig.


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
 path    The path to scan. By default we try the src/ dir.```

Options:
 --save  Whether we should save the file or not? Default: NO (default: false)

