<?php

namespace FirstClass;

use Symfony\Component\Console\Output\Output;

class SmartyToTwig {

    private $extensions = array();
    private $plugins = array();
    private $symfony_version = 2.0;
    private $paths = array();
    private $files = array();
    private $recursive = true;
    private $debug = true;
    private $twig;

    public function __construct($paths, $extensions = array('tpl', 'smarty'), $recursive = true) {
        $this->paths = (array) $paths;
        $this->setExtensions($extensions);
        $this->recursive = $recursive;
    }

    /**
     * Enabled the debug mode.
     * @param type $debug
     */
    public function setDebug($debug) {
        $this->debug = $debug;
    }

    /**
     * 
     * @param \Twig_Environment $twig
     */
    public function setTwigEnvironment(\Twig_Environment $twig) {
        $this->twig = $twig;
    }

    /**
     * 
     * @param array $extensions
     */
    public function setExtensions(array $extensions) {
        $this->extensions = $extensions;
    }

    /**
     * 
     * @param \Symfony\Component\Console\Output\Output $output
     */
    public function process($save = false, Output $output = null) {
        $this->processDir($this->paths);
        foreach ($this->files as $file) {
            /* @var $file \SplFileInfo */
            if ($output->getVerbosity() >= \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL) {
                $output->writeln('<info>Processing:' . $file->getRealPath() . '</info>');
            }
            $content = $this->convert(file_get_contents($file->getRealPath()));


            try {
                $this->twig->parse($this->twig->tokenize($content));

                if ($this->debug && $output) {
                    $output->writeln('<info>Parsed: ' . $file->getFilename() . ' : OK</info>');
                }
                
                if ($this->debug && $output) {
                    $output->writeln('<info>Looking for incompatible tags and such..</info>');
                    if(($errors = $this->findIncompatibilities($content))){
                        $output->writeln('<error>You have a incompatible tag(s): '.implode(',', $errors).' in '.$file->getFilename().'</error>');
                    }
                }
            } catch (\Exception $e) {
                $output->writeln('<error>' . $file->getFilename() . ': ' . $e->getMessage() . '</error>');
                if ($this->debug && $output) {
                    foreach (explode(PHP_EOL, $content) as $k => $l) {
                        $output->writeln(str_pad($k + 1, 4, ' ', STR_PAD_LEFT) . '| ' . $l);
                    }
                }
                if ($this->debug) {
                    exit();
                }
            }

            if ($save) {
                $extensions = array();
                foreach ($this->extensions as $ext) {
                    $extensions[] = '.' . $ext;
                }
                $filename = str_replace($extensions, '.twig', $file->getRealPath());

                if ($this->debug && $output) {
                    $output->write('<info>Saving file: ' . $filename . '</info>');
                }

                if (file_put_contents($filename, $content)) {
                    if ($this->debug && $output) {
                        $output->writeln('<info>:OK</info>');
                    }
                } else {
                    if ($this->debug && $output) {
                        $output->writeln('<info>:FAIL</info>');
                    }
                }
            }
        }
    }

    /**
     * 
     * @param string $content
     * @return string
     */
    public function convert($content) {

        $content = $this->runPlugin($content, 1);

        /* Convert simple {$name} */
        $content = preg_replace('/\{\$([^\}]+)\}/', '{{ $1 }}', $content);

        /* replace everything that starts with $ */
        $content = preg_replace('/\$([a-zA-Z0-9_]+)/', '$1', $content);


        /* .tpl to .twig */
        $content = preg_replace('/\.tpl/', '.twig', $content);


        /* Defined */
        $content = preg_replace('/isset\((\S+)\) == false/', '$1 is not defined', $content);
        $content = preg_replace('/!isset\((\S+)\)/', '$1 is not defined', $content);
        $content = preg_replace('/isset\((\S+)\)/', '$1 is defined', $content);

        /* empty */
        $content = preg_replace('/!empty\(([^\s)]+)\)(\s|\})/', '$1 is not empty $2 ', $content);
        $content = preg_replace('/empty\(([^\s]+)\)(\s|\})/', '$1 is empty $2 ', $content);

        /* empty */
        $content = preg_replace('/!is_null\(([^\s)]+)\)(\s|\})/', '$1 is not null $2 ', $content);
        $content = preg_replace('/is_null\(([^\s]+)\)(\s|\})/', '$1 is null $2 ', $content);


        /* Replace extends 
         * {extends file="file:[AutogespotBundle]/autogespot-2.0/head.html.tpl"}
         * {extends file="file:AutogespotBundle:autogespot-2.0:index.html.tpl"}
         */
        $content = preg_replace('/{extends file="file:(\[)?([a-zA-Z]+)(\])?(\/|:)([^\/]+)(\/|:)(.*).(tpl|twig)"}/', '{% extends "$2:$5:$7.twig" %}', $content);

        /* Replace simple if statements */
        $content = preg_replace_callback('/\{if([^\}]+)\}/', function($match) {
                    return '{% if' . preg_replace('/\$/', '', $match[1]) . ' %}';
                }, $content);
        $content = preg_replace('/\{\/if\}/', '{% endif %}', $content);
        $content = preg_replace('/\{else}/', '{% else %}', $content);
        $content = preg_replace('/\{else if([^}]+)\}/', '{% elseif$1%}', $content);
        $content = preg_replace('/\{elseif([^}]+)\}/', '{% elseif$1 %}', $content);

        /* Default with quotes */
        $content = preg_replace_callback('/\|default:\'([^\']+)?\'/', function($match) {
                    $v = preg_replace('/array\(\)/', '[]', @$match[1]);
                    return '|default(\'' . $v . '\')';
                }, $content);

        /* Default without quotes */
        $content = preg_replace_callback('/\|default:([^\s|\}]+)?/', function($match) {
                    $v = preg_replace('/array\(\)/', '[]', @$match[1]);
                    return '|default(' . $v . ')';
                }, $content);

        $content = preg_replace('/default:([^\)|\}]+)/', 'default($1)', $content);


        /* Block with append */
        $content = preg_replace_callback('/\{block name="([^"]+)"\s?(append)?\}/s', function($match) {

                    $name = $match[1];
                    $prependOrAppend = @$match[2];

                    $name = str_replace('-', '', $name);

                    $t = '{% block ' . $name . ' %}';

                    if ($prependOrAppend == 'append') {
                        $t .= '{{ parent() }}';
                    }

                    return $t;
                }, $content);

        /* Endblock */
        $content = preg_replace('/\{\/block\}/', '{% endblock %}', $content);

        /* Block with prepend */
        $content = preg_replace('/\{block name="([^"]+)" prepend}((?:(?!\{% endblock %\}).)*)?\{% endblock %\}/s', '{% block $1 %} $2 {{ parent() }}{% endblock %}', $content);

        /* nofilter */
        $content = preg_replace('/ nofilter/', '|raw', $content);

        /* include  */
        $content = preg_replace('/{include file="file:\[([^\]]+)\]\/([^\/]+)\/([^"]+).(twig|tpl|smarty)"}/', '{% include "$1:$2:$3.twig" %}', $content);
        $content = preg_replace('/{include file="file:([^\]]+):([^\/]+):([^"]+).(twig|tpl|smarty)"}/', '{% include "$1:$2:$3.twig" %}', $content);
        /* include with parameters */
        $content = preg_replace_callback('/\{include file="file:([^"]+)"([^\}]+)\}/', function($match) {
                    preg_match_all('/\[?([^\/|\]]+)\]?/', $match[1], $matches);

                    return '{% include "' . $matches[1][0] . ':' . $matches[1][1] . ':' . @$matches[1][2] . (@$matches[1][3] ? '/' . $matches[1][3] : '') . '" %}';
                }, $content);

        /* Replace all || and %% between {%%} */
        /* Find all the shorthand negative operators !variable and %% */
        $content = preg_replace_callback('/\{%((?:(?!%\}).)+)%\}/s', function($match) {
                    $content = $match[1];

                    $content = preg_replace('/!([^\s=]+)/', '$1 == false', $content);
                    $content = preg_replace('/\|\|/', 'or', $content);
                    $content = preg_replace('/&&/', 'and', $content);

                    return '{%' . $content . '%}';
                }, $content);

        /* Replace var.index iteration etc. */
        $content = preg_replace('/\$?[a-zA-Z0-9]+@index/', 'loop.index0', $content);
        $content = preg_replace('/\$?[a-zA-Z0-9]+@iteration/', 'loop.index', $content);
        $content = preg_replace('/\$?[a-zA-Z0-9]+@(first|last)/', 'loop.$1', $content);

        /* try to replace the simple modifiers */
        $content = preg_replace_callback('/\|([a-zA-Z_]+)/', function($match) {
                    $replace = array(
                        'strtoupper' => 'upper',
                        'strtolower' => 'lower',
                        'strip_tags' => 'striptags',
                        'count' => 'length',
                        'strtotime' => 'date_modify',
                        'ucfirst' => 'capitalize'
                    );
                    return '|' . str_replace(array_keys($replace), $replace, $match[1]);
                }, $content);

        /* strpos modifier
         * strpos(aAttributes[id].attributes_value, 'http://') */
        $content = preg_replace_callback('/strpos\(([^,]+),([^\)]+)\)( ([=]+) (false))?/', function($match) {

                    return $match[2] . (@$match[5] == 'false' ? 'not' : '') . ' in ' . $match[1];
                }, $content);

        /* number_format */
        $content = preg_replace('/\|number_format:([^:]+):([^:]+):([^\}|\s)]+)/', '|number_format($1, $2, $3)', $content);


        /* truncate requires text extension */
        $content = preg_replace('/\|truncate:(\'||")?([0-9]+)(\'|")?:(\'|")([^\}]+)(\'|")/', '|truncate($2, \'$5\')', $content);


        /* Replace */
        $content = preg_replace('/\|replace:\'([^\']+)\':\'([^\']+)?\'/', '|replace({ \'$1\': \'$2\' })', $content);


        /* catenate */
        $content = preg_replace('/\|cat:/', ' ~ ', $content);



        /* Replace foreach */
        $content = preg_replace('/\{foreachelse\}/', '{% else %}', $content);
        $content = preg_replace_callback('/\{for(each)?([^\}]+)\}/', function($match) {
                    $var = '';
                    $in = '';
                    $operator = 'in';


                    if (preg_match('/(\S+) as (\S+)=>(\S+)/', $match[2], $matches) !== 0) {
                        $var = $matches[2] . ',' . $matches[3];
                        $in = $matches[1];
                    } elseif (preg_match('/(\S+) as (\S+)/', $match[2], $matches) !== 0) {
                        $var = $matches[2];
                        $in = $matches[1];
                    } elseif (preg_match('/from=(\S+) item="?([^"]+)"?/', $match[2], $matches) !== 0) {
                        $var = $matches[2];
                        $in = $matches[1];
                    } elseif (preg_match('/item=(\S+) from=(\S+)/', $match[2], $matches) !== 0) {
                        $var = $matches[1];
                        $in = $matches[2];
                    } elseif (preg_match('/(\S+)=([0-9]+) to ([0-9]+)/', $match[2], $matches) !== 0) {
                        $var = $matches[1];
                        $in = $matches[2] . '..' . $matches[3];
                    } elseif (preg_match('/(\S+)=([^>]+) to (\S+)/', $match[2], $matches) !== 0) {
                        $var = $matches[1];
                        $in = $matches[2] . '..' . $matches[3];
                    } else {
                        var_dump($match);
                    }

                    return '{% for ' . $var . ' ' . $operator . ' ' . $in . ' %}';
                }, $content);
        $content = preg_replace('/\{\/for(each)?\}/', '{% endfor %}', $content);


        $content = preg_replace('/sprintf:([^\}\|\)]+)/', 'format($1)', $content);

        $content = preg_replace('/app\.getRequest\(\)\.attributes\.get\(\'([^\']+)\'\)/', 'app.request.attributes.get(\'$2\')', $content);


        /* Smarty specifics */
        $content = preg_replace_callback('/smarty\.([^\}|\||\)|\s]+)/', function($match) {
                    if (preg_match('/get\.(\S+)/', $match[1], $matches) !== 0) {
                        return 'app.request.query.get(\'' . $matches[1] . '\')';
                    } elseif (preg_match('/session\.(\S+)/', $match[1], $matches) !== 0) {
                        return 'app.session.get(\'' . $matches[1] . '\')';
                    } elseif (preg_match('/server\.(\S+)/', $match[1], $matches) !== 0) {
                        return 'app.request.server.get(\'' . $matches[1] . '\')';
                    } elseif (preg_match('/now/', $match[1], $matches) !== 0) {
                        return "'now'";
                    } elseif (preg_match('/section\.(\S+)\.index/', $match[1], $matches) !== 0) {
                        return "loop.index0";
                    } elseif (preg_match('/section.(\S+).last/', $match[1], $matches) !== 0) {
                        return "loop.last";
                    }

                    
                }, $content);

        /* Replace comment */
        $content = preg_replace('/\{\*\}((?:(?!{\*}).)*){\*\}/ms', '{# $1 #}', $content);
        $content = preg_replace('/\{\*((?:(?!\*\}).)*)\*\}/ms', '{# $1 #}', $content);

        /* url */
        $content = preg_replace_callback('/\{url([^\}]+)?\}((?:(?!\{\/url\}).)*)\{\/url\}/', function($match) {

                    $route = strpos($match[2], '{') === 0 ? str_replace(array('{', '}'), '', $match[2]) : '\'' . $match[2] . '\'';

                    $a = array();
                    if (preg_match_all('/([^\s]+)=\(([^\}|\)]+)\)(.*)/', $match[1], $matches) !== 0) {

                        if (isset($matches[0])) {
                            foreach ($matches[0] as $i => $m) {
                                $a[$matches[1][$i]] = '\'' . $matches[1][$i] . '\': (' . $matches[2][$i] . ')' . @$matches[3][$i];
                            }
                        }
                    }

                    if (preg_match_all('/([^\s]+)=([^\}\s]+)/', $match[1], $matches) !== 0) {
                        if (isset($matches[0])) {
                            foreach ($matches[0] as $i => $m) {
                                if (!array_key_exists($matches[1][$i], $a)) {
                                    $a[$matches[1][$i]] = '\'' . $matches[1][$i] . '\': ' . $matches[2][$i];
                                }
                            }
                        }
                    }

                    /* Normalize route */
                    if(strpos($route, '{{') !== false){
                        $route = str_replace(array('{{', '}}'), array('\' ~ (', ') ~ \''), $route);
//                        $route = $route;
                    }
                    
                    if ($a) {
                        return '{{ url(' . $route . ', { ' . implode(', ', $a) . ' } ) }}';
                    } else {
                        return '{{ url(' . $route . ') }}';
                    }
                }, $content);


        /* capture
         * {capture assign="fb_locale"}
         */
        $content = preg_replace('/{capture (assign|name)="([^"]+)"}/', '{% set $2 %}', $content);
        $content = preg_replace('/{\/capture}/', '{% endset %}', $content);

        /* assign */
        $content = preg_replace('/{assign var="([^"]+)" value=([^}]+)}/', '{% set $1 = $2 %}', $content);

        /* {strip} */
        $content = preg_replace('/\{strip\}/', '{% spaceless %}', $content);
        $content = preg_replace('/\{\/strip\}/', '{% endspaceless %}', $content);
        
        /* asset */
        $content = preg_replace('/{asset}([\'{]+){\/asset}/', '{{ asset(\'$1\') }}', $content);

        $content = preg_replace('/{path}((?:(?!\{\/path\}).)*){\/path}/', '{{ path(\'$1\') }}', $content);

        $content = preg_replace_callback('/{asset}((?:(?!{asset}).)*){\/asset}/', function($match) {

                    if (strpos($match[1], '{{') === false) {
                        return '{{ asset(\'' . $match[1] . '\') }}';
                    } else {
//                        var_dump($match);
                        $c = str_replace('\'', '"', $match[1]);
                        $c = str_replace('{{', '\' ~ ', $c);
                        $c = str_replace('}}', ' ~ \'', $c);
                        return '{{ asset(\'' . $c . '\') }}';
                    }
                }, $content);

        $content = $this->TwigRenderTag($content);


        /* {javascripts
          assets='@AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/jquery-ui-1.8.18.custom.min.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/jquery.tipsy.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/jquery.fancybox.pack.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/plupload.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/plupload.flash.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/plupload.html4.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/plupload.html5.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/jquery.plupload.queue.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/css3-mediaqueries.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/jquery-ui-1.8.18.custom.min.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/detectmobilebrowser.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/jquery.selectboxes.min.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/twitter.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/extensions.js,
          @AutogespotBundle/Resources/public/autogespot-2.0/JavaScript/script.js'
          }
          <script src="{$asset_url}"></script>{/javascripts}
         * 
          {% javascripts
          '@AcmeFooBundle/Resources/public/js/*'
          '@AcmeBarBundle/Resources/public/js/form.js'
          '@AcmeBarBundle/Resources/public/js/calendar.js' %}
          <script src="{{ asset_url }}"></script>
          {% endjavascripts %}

         * 
         */
        $content = preg_replace_callback('/\{javascripts([^\}]+)\}((?:(?!\{\/javascripts\}).)*)\{\/javascripts\}/s', function($match) {
                    preg_match_all('/(@[^\s\}\',]+)/', $match[1], $matches);
                    $js = array();
                    foreach ($matches[1] as $m) {
                        $js[] = "'" . $m . "'";
                    }
                    return '{% javascripts ' . implode(PHP_EOL, $js) . ' %}<script src="{{ asset_url }}"></script>
                        {% endjavascripts %}';
                }, $content);

        /* Replace the remaining string starting things.. */
        $content = preg_replace('/\{"([^"]+)"([^\}]+)\}/', '{{ "$1"$2 }}', $content);

        /* Replace object notation by . notation {{ spot->getName() }} becomes {{ spot.getName() }} */
        $content = preg_replace_callback('/\{%((?:(?!%\}).)+)%\}/', function($match) {
                    return '{%' . str_replace('->', '.', $match[1]) . '%}';
                }, $content);
        $content = preg_replace_callback('/\{\{((?:(?!\}\}).)+)\}\}/', function($match) {
                    return '{{' . str_replace('->', '.', $match[1]) . '}}';
                }, $content);

        /* Get variables behave differt */
        $content = preg_replace('/app.request.query.get\(\'([^\']+)\'\) is not defined/', 'app.request.query.get(\'$1\') is null', $content);
        $content = preg_replace('/app.request.query.get\(\'([^\']+)\'\) is defined/', 'app.request.query.get(\'$1\')', $content);

        $content = $this->runPlugin($content, 100);


        return $content;
    }

    public function plugin($callback, $priority = 100) {
        $this->plugins[$priority][] = $callback;
    }

    private function runPlugin($content, $priority) {
        if (isset($this->plugins[$priority])) {
            foreach ($this->plugins[$priority] as $plugin) {
                $content = call_user_func($plugin, $content);
            }
        }
        return $content;
    }

    public function convertLegacy($content) {


        $content = preg_replace('/\{\(((?:(?!\)\}).)*)\)\}/', '{{ ($1) }}', $content);

        $content = str_replace('{for $i=$smarty.now|date_format:\'%Y\' to ($smarty.now|date_format:\'%Y\'+5)}', '{% for i in \'now\'|date(\'%Y\')..(\'now\'|date(\'%Y\')+5) %}', $content);


        $content = str_replace('{for $year=2012 to ($smarty.now|date_format:\'%Y\')}', '{% for year in 2012..(\'now\'|date(\'Y\')) %}', $content);
        $content = str_replace('{for $month=1 to 12}', '{% for month in 1..12 %}', $content);
        $content = str_replace('{for $value=$smarty.now|date_format:\'%Y\' to 1960 step -1}', '{% for value in (\'now\'|date(\'Y\'))..1960 %}', $content);
        $content = str_replace('{for $i=$smarty.now|date_format:\'%Y\' to 1980 step -1}', '{% for i in (\'now\'|date(\'Y\'))..1980 %}', $content);

        $content = str_replace('->count()', '|length', $content);



        /* Replace short false operators >> if !var   */
        $content = preg_replace('/!\$([^\s\}]+)/', '$1 == false', $content);




        /* Replace smarty specifics */

        $content = preg_replace('/\$smarty.server.([^.\s\}]+)/', 'app.request.server.get(\'$1\')', $content);
        $content = preg_replace('/\{\$smarty.get.([^\s\}\|\)\}]+)([^\}]+)?\}/', '{{ app.request.query.get(\'$1\')$2 }}', $content);
        $content = preg_replace('/\$smarty.get.([^\s\}\|\)]+)/', 'app.request.query.get(\'$1\')', $content);
        $content = preg_replace('/\$app->getRequest\(\)->attributes->get/', 'app.request.attributes.get', $content);

        $content = preg_replace('/\{\$?app\.request\.attributes\.get\(\'([^\']+)\'\)}/', '{{ app.request.attributes.get(\'$1\') }}', $content);

        $content = preg_replace('/{app\.request\.query\.get\(\'([^\']+)\'\)}/', '{{ app.request.query.get(\'$1\') }}', $content);

        $content = preg_replace('/\$smarty.server.([^\S]+)}/', '{{ app.request.server.get(\'$1\') }}', $content);


        /* Replace variables {$_Vars_} */
        $content = preg_replace('/\{\$([^\}]+)\}/', '{{ $1 }}', $content);

        /* Replace variables {$_Vars_} */
        $content = preg_replace('/\$([^\$\-]+)/', '$1', $content);

        /* Replace count by length */
        $content = preg_replace('/\|count/', '|length', $content);




        /* replace the && and || by and in if */
        $content = preg_replace('/&&/', 'and', $content);
        $content = preg_replace('/\|\|/', 'or', $content);
        $content = preg_replace('/===/', '==', $content);

        /* replace if */
        $content = preg_replace('/\{if ([^\}]+)\}/', '{% if $1 %}', $content);
        $content = preg_replace('/\{\/if\}/', '{% endif %}', $content);


        /* replace else */
        $content = preg_replace('/\{else\}/', '{% else %}', $content);


        /* Block */
        $content = preg_replace_callback('/\{block name="([^"]+)"\s?(append)?\}/s', function($match) {

                    $name = $match[1];
                    $prependOrAppend = @$match[2];

                    $name = str_replace('-', '', $name);

                    $t = '{% block ' . $name . ' %}';

                    if ($prependOrAppend == 'append') {
                        $t .= '{{ parent() }}';
                    }

                    return $t;
                }, $content);

        $content = preg_replace('/\{\/block\}/', '{% endblock %}', $content);


        $content = preg_replace('/\{block name="([^"]+)" prepend}((?:(?!\{% endblock %\}).)*)?\{% endblock %\}/s', '{% block $1 %} $2 {{ parent() }}{% endblock %}', $content);



        /* Truncate */
        $content = preg_replace('/\|truncate:(\')?([0-9]+)(\')?:\'([^\}]+)\'/', '|truncate($2, \'$4\')', $content);

        /* Default */
        $content = preg_replace('/\|default:\'([^)|\'|\||\s]+)?\'/', '|default(\'$1\')', $content);
        $content = preg_replace('/\|default:([^\)|\||\}\s]+)/', '|default($1$2$3)', $content);

        /* date_format */
        $content = preg_replace('/\|date_format:([^}]+)/', '|date_format($1)', $content);

        /* Replace loop variabels */
        $content = preg_replace('/($)?[a-zA-Z]+\@index/', 'loop.index0', $content);
        $content = preg_replace('/($)?[a-zA-Z]+\@iteration/', 'loop.index', $content);
        $content = preg_replace('/($)?[a-zA-Z]+\@first/', 'loop.first', $content);
        $content = preg_replace('/($)?[a-zA-Z]+\@last/', 'loop.last', $content);

        /* Replace comment */
        $content = preg_replace('/\{\*\}((?:(?!{\*}).)*){\*\}/ms', '{# $1 #}', $content);

        /* Replace object style "->" */
        do {
            $content = preg_replace_callback('/([a-zA-Z0-9\.\(\)\-\>]+)->([a-zA-Z0-9_]+)(\()?([^\)]+)?(\))?/', function($match) {

                        return $match[1] . '.' . $match[2] . ($match[4] ? @$match[3] . $match[4] . @$match[5] : '');
                    }, $content, -1, $count);
        } while ($count != 0);

        /* Replace extends 
         * {extends file="file:[AutogespotBundle]/autogespot-2.0/head.html.tpl"}
         * {extends file="file:AutogespotBundle:autogespot-2.0:index.html.tpl"}
         */
        $content = preg_replace('/{extends file="file:(\[)?([a-zA-Z]+)(\])?(\/|:)([^\/]+)(\/|:)(.*).tpl"}/', '{% extends "$2:$5:$7.twig" %}', $content);

        /* strtotime to date_modify */
        $content = preg_replace('/strtotime/', 'date_modify', $content);

        /* array() to [] */
//            $content = preg_replace('/array\(\)/', '[]', $content);

        /* renderWeblogPlugin */
        $content = preg_replace('/\{renderWeblogPlugin id=([^\} ]+)( intro=(true|false))?}/', '{{ renderWeblogPlugin($1, $3) }}', $content);

        /* Render tags */
        $content = preg_replace_callback('/\{render([^}]+)\}([^{]+){\/render\}/', function($match) {

                    preg_match_all('/([^\[\]=\s,]+)=>([^\s\]=,]+)/', $match[1], $matches);
                    $a = array();

                    foreach ($matches[1] as $i => $m) {
                        if ($m == 'standalone')
                            continue;
                        $a[] = '\'' . $m . '\':' . $matches[2][$i];
                    }

                    return '{% render \'' . $match[2] . '\' with { ' . implode(', ', $a) . ' }, {\'standalone\': true } %}';
                }, $content);


        /* include  */
        $content = preg_replace('/{include file="file:\[([^\]]+)\]\/([^\/]+)\/([^"]+).tpl"}/', '{% include "$1:$2:$3.twig" %}', $content);
        $content = preg_replace('/{include file="file:([^\]]+):([^\/]+):([^"]+).smarty"}/', '{% include "$1:$2:$3.twig" %}', $content);

        /* include with params 
         * {include file="file:[AutogespotBundle]/autogespot-2.0/includes/livestream.html.tpl" cssclass="livestream-side" playerheight="233"}
         *  */
        $content = preg_replace_callback('/{include file="file:\[([^\]]+)\]\/([^\/]+)\/([^"]+)"(.*)}/', function($match) {
                    preg_match_all('/([a-zA-Z_]+)\=(")?([^"=\s]+)(")?/', $match[4], $matches);

                    $a = array();

                    foreach ($matches[1] as $i => $m) {
                        $a[] = '"' . $m . '" : ' . $matches[2][$i] . $matches[3][$i] . $matches[4][$i];
                    }

                    return '{% include "' . $match[1] . ':' . $match[2] . ':' . str_replace('.tpl', '.twig', $match[3]) . '" with { ' . implode(', ', $a) . ' } %}';
                }, $content);


        /* foreach */
        $content = preg_replace('/{foreach item=([^ ]+) from=([^}]+)}/', '{% for $1 in $2 %}', $content);
        $content = preg_replace('/{foreach ([^ ]+) as ([^}%]+)}/', '{% for $2 in $1 %}', $content);
        $content = preg_replace('/{foreach from=([^}]+) item=([^ ]+)}/', '{% for $2 in $1 %}', $content);
        $content = preg_replace('/{foreach ([^\(]+)\(([^\(]+)\) as ([^\}]+)}/', '{% for $3 in $1($2) %}', $content);
        $content = preg_replace('/{foreach ([^ ]+) item=([^}%]+)/', '{% for $2 in $1 %}', $content);

        $content = preg_replace('/{\/foreach}/', '{% endfor %}', $content);


        $content = preg_replace('/{% for ([^=]+)=>([^\s]+) in ([^\s]+) %}/', '{% for $1,$2 in $3 %}', $content);

        $content = preg_replace('/{for ([a-zA-Z]+)=([0-9]+) to ([0-9]+)}/', '{% for $1 in $2..$3 %}', $content);


        /* for 
         * {for i="now"|date('%Y' to 1980 step -1)}
         */
        $content = str_replace('{for $i=$smarty.now|date_format:\'%Y\' to 1980 step -1}', '{% for i in \'now\'|date(\'%Y\')..1980 %}', $content);

        $content = preg_replace('/{\/for}/', '{% endfor %}', $content);


        /* Strings beginning */
        $content = preg_replace('/{"([^"]+)"([^}]+)}/', '{{ "$1"$2 }}', $content);

        /* ucfirst to capitalize */
        $content = preg_replace('/ucfirst/', 'capitalize', $content);




        $content = preg_replace('/!isset\(([^\s)]+)\)(\s|\})/', '$1 is not defined $2 ', $content);
        $content = preg_replace('/isset\(([^\s]+)\)(\s|\})/', '$1 is defined $2 ', $content);

        /* empty */
        $content = preg_replace('/!empty\(([^\s)]+)\)(\s|\})/', '$1 is not empty $2 ', $content);
        $content = preg_replace('/empty\(([^\s]+)\)(\s|\})/', '$1 is empty $2 ', $content);

        /* assign */
        $content = preg_replace('/{assign var="([^"]+)" value=([^}]+)}/', '{% set $1 = $2 %}', $content);

        /* uppercase */
        $content = preg_replace('/\|strtoupper/', '|upper', $content);
        $content = preg_replace('/\|strtolower/', '|lower', $content);

        /* capture
         * {capture assign="fb_locale"}
         */
        $content = preg_replace('/{capture (assign|name)="([^"]+)"}/', '{% set $2 %}', $content);
        $content = preg_replace('/{\/capture}/', '{% endset %}', $content);


        /* smarty.now */
        $content = preg_replace('/smarty\.now/', '"now"', $content);



        /* getSetting */
        $content = preg_replace('/{getSetting (key|name)="([^"]+)"}/', '{{ getSetting(\'$2\') }}', $content);


        /* catenate */
        $content = preg_replace('/\|cat:/', ' ~ ', $content);

        /* catenate */
        $content = preg_replace('/\|sprintf:([^\}\s]+)/', '|sprintf($1)', $content);
        $content = preg_replace('/\|sprintf:\(([^\)\s]+)\)/', '|sprintf($1)', $content);

        /* number_format */
        $content = preg_replace('/\|number_format:([^:]+):([^:]+):([^\})]+)/', '|number_format($1, $2, $3)', $content);

        /* Replace */
        $content = preg_replace('/\|replace:\'([^\']+)\':\'([^\']+)?\'/', '|replace( \'$1\' , \'$2\' )', $content);

        $content = preg_replace_callback('/\|sanitizeYoutubeUrl(:)?(true)?/', function($match) {
                    return '|sanitizeYoutubeUrl' . ( @$match[2] ? '(true)' : '');
                }, $content);

        /* cleanurl */
        $content = preg_replace_callback('/\|cleanurl(:[^\}\s\)]+)?/', function($match) {
                    return '|cleanurl' . (@$match[2] ? '(true)' : '');
                }, $content);

        /* url */
        $content = preg_replace_callback('/\{url([^\}]+)?\}((?:(?!\{\/url\}).)*)\{\/url\}/', function($match) {

                    preg_match_all('/([^\s]+)=([^\s\}]+)/', $match[1], $matches);
                    $a = array();
                    foreach (@$matches[0] as $i => $m) {
                        $a[] = '\'' . $matches[1][$i] . '\' : ' . $matches[2][$i];
                    }

                    $route = strpos($match[2], '{') === 0 ? str_replace(array('{', '}'), '', $match[2]) : '\'' . $match[2] . '\'';


                    return '{{ url(' . $route . ', { ' . implode(', ', $a) . ' } ) }}';
                }, $content);

        $content = preg_replace('/strip_tags/', 'striptags', $content);

        $content = preg_replace('/{getSpotCountLast24Hours}/', '{{ getSpotCountLast24Hours() }}', $content);
        $content = preg_replace('/{getSpotCount}/', '{{ getSpotCount() }}', $content);


        $content = preg_replace('/smarty.capture.([a-zA-Z]+)/', '$1', $content);

        $content = preg_replace('/{app.request.query.get\(\'([^\']+)\'\)\|default\(\'([^\']+)?\'\)}/', '{{ app.request.query.get(\'$1\')|default(\'$2\') }}', $content);

        $content = preg_replace('/{elseif([^}]+)}/', '{% elseif$1 %}', $content);


        /* asset */
        $content = preg_replace('/{asset}([\'{]+){\/asset}/', '{{ asset(\'$1\') }}', $content);

        $content = preg_replace('/{path}((?:(?!\{\/path\}).)*){\/path}/', '{{ path(\'$1\') }}', $content);

        $content = preg_replace_callback('/{asset}((?:(?!{asset}).)*){\/asset}/', function($match) {

                    $q = strpos($match[1], 'path') !== false ? '' : '\'';

                    $x = '{{ asset(' . $q . preg_replace('/{{((?:(?!}}).)*)}}/', ($q ? '\' ~ $1 ~\'' : '$1'), $match[1]) . $q . ') }}';

                    return $x;
                }, $content);

        $content = preg_replace('/\{content id="([^"]+)"\}/', '{{ content(_locale, $1) }}', $content);

        $content = str_replace('app.request.query.get(\'spotter\') is defined   == false and app.request.query.get(\'color\') is defined   == false and', '', $content);

        $content = preg_replace('/strpos\(([^,]+), \'([^\']+)\'\) \=\= false/', '\'$2\' not in \'$1\'', $content);

        $content = preg_replace('/{section name=([^\s]+) loop=([^\s]+)}/', '{% for $1 in 1..$2 %}', $content);
        $content = preg_replace('/\{\/section\}/', '{% endfor %}', $content);


        /* bool */
        $content = preg_replace_callback('/\|bool:([^\s:]+)(:([^\s\}]+))?/', function($match) {
//                var_dump($match);

                    return ' ? ' . $match[1] . ' : ' . (@$match[3] ? $match[3] : '\'\'');
//                        return '|bool(' . $match[1] . $match[2] . $match[3] . (@$match[4] ? ',' . $match[5] . $match[6] . $match[7] : '') . ')';
                }, $content);

        /* replace nofilter by |raw */
        $content = preg_replace('/ nofilter/', '|raw', $content);
        return $content;
    }

    private function TwigRenderTag($content) {
        if ($this->symfony_version == 2.0) {
            /* Render tags */
            $content = preg_replace_callback('/\{render([^}]+)\}([^{]+){\/render\}/', function($match) {

                        preg_match_all('/([^\[\]=\s,]+)=>([^\s\]=,]+)/', $match[1], $matches);
                        $a = array();

                        foreach ($matches[1] as $i => $m) {
                            if ($m == 'standalone')
                                continue;
                            $a[] = '\'' . $m . '\':' . $matches[2][$i];
                        }

                        return '{% render \'' . $match[2] . '\' with { ' . implode(', ', $a) . ' }, {\'standalone\': true } %}';
                    }, $content);
        }
        return $content;
    }

    /**
     * Iterate through the directories given.
     */
    private function processDir(array $paths = array()) {

        foreach ($paths as $path) {
            $files = new \RecursiveDirectoryIterator(
                    $path, \FilesystemIterator::SKIP_DOTS
            );

            foreach ($files as $file) {
                /* If its a dir and rerursive is true, go deeper */
                if ($file->isDir() && $this->recursive) {
                    $this->processDir((array) $file->getRealPath());
                } else {
                    /* Check if the extension is registered */
                    if (in_array($file->getExtension(), $this->extensions)) {
                        $this->files[] = $file;
                    }
                }
            }
        }
    }

    private function findIncompatibilities($content){
        $list = array('{break}', '{continue}', '|unescape', '{nocache}',
            '{literal}', '{while}');
        $errors = array();
        foreach($list as $find){
            if(strpos($content, $find) !== false){
                $errors[] = $find;
            }
        }
        return $errors;
    }
}