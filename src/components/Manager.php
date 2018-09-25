<?php
/**
 *
 * User: develop
 * Date: 03.10.2017
 */

namespace somov\appmodule\components;


use somov\appmodule\Config;
use somov\appmodule\interfaces\AppModuleInterface;
use somov\common\helpers\ReflectionHelper;
use somov\common\traits\ContainerCompositions;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\base\Event;
use yii\base\Exception;
use yii\base\Module;
use yii\base\Security;
use yii\base\UnknownMethodException;
use yii\caching\Cache;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\web\Application;


/**
 * Class ModuleManager
 * @package app\components
 *
 * @property Cache $cache
 */
class Manager extends Component implements BootstrapInterface
{
    use ContainerCompositions;

    CONST EVENT_BEFORE_INSTALL = 'beforeInstall';
    CONST EVENT_AFTER_INSTALL = 'afterInstall';

    CONST EVENT_BEFORE_UNINSTALL = 'beforeUnInstall';
    CONST EVENT_AFTER_UNINSTALL = 'afterUnInstall';

    CONST EVENT_BEFORE_CHANGE_STATE = 'beforeChangeState';
    CONST EVENT_AFTER_CHANGE_STATE = 'afterChangeState';

    CONST EVENT_BEFORE_UPGRADE = 'beforeUpgrade';
    CONST EVENT_AFTER_UPGRADE = 'afterUpgrade';

    /** Turn on module after reset oo install
     * @var bool
     */
    public $isAutoActivate = false;

    public $places = [
        'modules' => '@app/modules'
    ];

    public $baseNameSpace = 'app\modules';

    public $processAjax = false;

    /**
     * @var array|string
     */
    public $cacheConfig = [
        'class' => 'yii\caching\FileCache',
        'keyPrefix' => 'modules',
    ];

    /** @var array|null */
    public $cacheDependencyConfig = null;

    /**
     * @var array|callable
     */
    public $cacheVariations = [];

    /**
     * Bootstrap method to be called during application bootstrap stage.
     * @param \yii\base\Application $app the application currently running
     */
    public function bootstrap($app)
    {
        if (!$this->processAjax && $app instanceof Application && $app->request->isAjax) {
            return;
        }

        $this->addAppModulesToApplication();
        $this->registerEvents();
    }


    /**
     * @return \yii\caching\CacheInterface
     */
    protected function getCache()
    {
        if (is_string($this->cacheConfig)) {
            return \Yii::$app->{$this->cacheConfig};
        }
        $config = $this->cacheConfig;
        return $this->getComposition(ArrayHelper::remove($config, 'class'), $config);
    }

    private function getCacheKey()
    {
        if (is_callable($this->cacheVariations)) {
            $this->cacheVariations = call_user_func($this->cacheVariations);
        }
        return array_merge([__CLASS__], (array)$this->cacheVariations);
    }

    /**
     * @return null|object
     */
    private function getCacheDependency()
    {
        if (is_array($this->cacheDependencyConfig)) {
            return \Yii::createObject($this->cacheDependencyConfig);
        }
        return null;
    }

    /**
     * @param string $file file name of module class
     * @param bool $reloadClass
     * @return null|Config
     */
    private function initConfig($file, $reloadClass = false)
    {

        if (!file_exists($file)) {
            return null;
        }

        ReflectionHelper::initClassInfo($file, $info);

        $path = dirname($file);

        /**@var AppModuleInterface $class */
        $class = $info['class'];

        if (class_exists($class) && $reloadClass) {
            $suffix = ucfirst(strtr((new Security())->generateRandomString(10), ['_' => '', '-' => '']));
            $content = preg_replace('/class\s*(Module)\s*extends/',
                'class Module' . $suffix . ' extends', file_get_contents($file));
            $reloadFile = $path . DIRECTORY_SEPARATOR . 'Module' . $suffix . '.php';
            file_put_contents($reloadFile, $content);
            $config = $this->initConfig($reloadFile);
            unlink($reloadFile);
            return $config;
        }

        $alias = str_replace('\\', '/', $info['namespace']);
        \Yii::setAlias($alias, $path);
        \Yii::$classMap[(string)$class] = $file;

        if (in_array(AppModuleInterface::class, class_implements($class))) {

            $config = new Config([
                'runtime' => [
                    'namespace' => $info['namespace'],
                    'class' => $class,
                    'path' => $path
                ]
            ]);
            $class::configure($config);
            $config->isEnabled();

            if ($reloadClass) {
                \Yii::setAlias($alias, null);
            }

            return $config;
        };

        return null;
    }

    /** Массив конфигураций модулей = ['id' => '', 'path' => '', 'enabled', 'class']
     * если нет в кеше - обходит каталог из альяса @modulesAlias
     * @return Config[]
     */
    public function getModulesClassesList()
    {

        $places = $this->places;

        return $this->getCache()->getOrSet($this->getCacheKey(), function () use ($places) {
            $r = [];
            foreach ($places as $place => $alias) {
                foreach (FileHelper::findFiles(\Yii::getAlias($alias), [
                    'only' => ['pattern' => '*Module.php']
                ]) as $file) {
                    if ($config = $this->initConfig($file)) {
                        if (isset($config->parentModule) && isset($r[$config->parentModule])) {
                            /** @var Config $parent */
                            $parent = $r[$config->parentModule];
                            $parent->addModules([
                                $config->id => [
                                    'class' => $config->class,
                                    'version' => $config->version
                                ]
                            ]);
                        }
                        $r[$config->id] = $config;
                    }
                }
            }
            return $r;
        }, null, $this->getCacheDependency());
    }


    /**
     * @param array $filter
     * @return Config[]
     */
    public function getFilteredClassesList($filter = ['enabled' => true])
    {
        if (empty($filter)) {
            return $this->getModulesClassesList();
        }

        return array_filter($this->getModulesClassesList(), function ($a) use ($filter) {
            foreach ($filter as $attribute => $value) {
                if (is_scalar($value)) {
                    if ($a->$attribute != $value) {
                        return false;
                    }
                } else {
                    foreach ($value as $item) {
                        if (!in_array($item, $a->$attribute)) {
                            return false;
                        };
                    }
                }
            }
            return true;
        });
    }

    /**
     * @param string $id
     * @return mixed|null|Config
     */
    public function getModuleConfigById($id)
    {
        if ($list = $this->getFilteredClassesList(['id' => $id])) {
            return reset($list);
        }
        return null;
    }

    /**
     * @param Config $config
     */
    protected function addModule(Config $config)
    {

        \Yii::$app->setModule($config->id, [
            'class' => $config->class,
            'version' => $config->version,
            'modules' => $config->modules
        ]);

        if ($config->nameSpace !== ($this->baseNameSpace . '\\' . $config->id)) {
            \Yii::setAlias($config->nameSpace, $config->path);
        }

        if (!empty($config->urlRules)) {
            \Yii::$app->urlManager->addRules($config->urlRules, $config->appendRoutes);
        }

        if ($config->bootstrap) {
            $module = \Yii::$app->getModule($config->id);
            if ($module instanceof BootstrapInterface) {
                $module->bootstrap(\Yii::$app);
            }
        }
    }

    /** Добавляет классы модулей в конфигурацию приложения
     * @param array $filter
     */
    private function addAppModulesToApplication($filter = ['enabled' => true])
    {
        foreach ($this->getFilteredClassesList($filter) as $config) {
            /**@var  Config $config */
            $this->addModule($config);
        }
    }

    /** Регистрация событий*/
    private function registerEvents()
    {
        foreach ($this->getFilteredClassesList() as $config) {
            if ($events = $config->events) {
                foreach ($events as $class => $classEvents) {
                    foreach ($classEvents as $classEvent) {
                        Event::on($class, $classEvent, [$this, $config->eventMethod], [
                            'moduleConfig' => $config
                        ]);
                    }
                }
            }
        }
    }

    public static function generateMethodName($event)
    {
        $reflector = new \ReflectionClass($event->sender);
        $name = $reflector->getShortName();
        //Удаляем суффикс классов моделей из тестов
        $name = strtr($name, ['Clone' => '']);

        return lcfirst($name) . ucfirst($event->name);
    }

    /** Передача события объекту обработчику
     * @param Event $event
     * @deprecated
     */
    public function _eventByMethod($event)
    {
        /** @var Config $config */
        $config = $event->data['moduleConfig'];
        /** @var Module|AppModuleInterface $module */
        $module = call_user_func([$config->class, 'getInstance']);
        $handler = $module->getModuleEventHandler();

        $method = self::generateMethodName($event);

        if (method_exists($handler, $method)) {
            call_user_func_array([$module, $method], ['event' => $event]);
        } else {
            $this->_eventToEventObject($event, $module);
        }
    }

    /**
     * @param $event
     * @param AppModuleInterface $module
     * @return void
     * @deprecated
     */
    public function _eventToEventObject($event, AppModuleInterface $module = null)
    {
        $module = ($module) ? $module : \Yii::$app->getModule($event->data['moduleConfig']->id);
        if ($handler = $module->getModuleEventHandler()) {
            $handler->handleModuleEvent($event, $module);
        } else {
            throw new \RuntimeException("$module->id not valid App module");
        }
    }


    public function clearCache()
    {
        $this->getCache()->offsetUnset($this->getCacheKey());
        return $this;
    }


    private function getTmpPath($forFile = null)
    {
        $path = \Yii::getAlias('@runtime/modules');
        if (!file_exists($path)) {
            mkdir($path);
        }

        if (isset($forFile)) {
            $path .= DIRECTORY_SEPARATOR . basename($forFile, '.zip');
            if (file_exists($path)) {
                FileHelper::removeDirectory($path);
            }
            mkdir($path);
        }

        return $path;
    }


    /**
     * @param $id
     * @param $config
     * @return null|\yii\base\Module|AppModuleInterface
     * @throws \yii\base\Exception
     */
    public function loadModule($id, &$config)
    {
        if (!$config = $this->getModuleConfigById($id)) {
            throw new Exception('Unknown module ' . $id);
        }
        $this->addAppModulesToApplication(['id' => $id]);
        return \Yii::$app->getModule($id);
    }

    /**
     * @param Config $exist
     * @param Config $new
     * @return bool
     */
    protected function upgrade(Config $exist, Config $new)
    {
        if (version_compare($exist->version, $new->version, '>=')) {
            return true;
        }
        /** @var Module|AppModuleInterface $instance */
        $instance = \Yii::createObject($new->class, ['id' => $new->id, \Yii::$app]);

        $this->trigger(self::EVENT_BEFORE_UPGRADE, new ModuleEvent(['module' => $instance]));

        if ($instance->hasMethod('upgrade') && !$instance->upgrade()) {
            return false;
        }
        FileHelper::removeDirectory($exist->path);
        rename($new->path, $exist->path);

        $instance->version = $new->version;

        $this->clearCache();
        $this->trigger(self::EVENT_AFTER_UPGRADE, new ModuleEvent(['module' => $instance]));

        return true;
    }

    protected function installFiles($filesPath, Config $config)
    {
        $path = $config->getInstalledPath();
        FileHelper::createDirectory($path);
        rename($filesPath, $path);
    }

    public function install($fileName, &$error)
    {
        try {
            $tmp = $this->getTmpPath($fileName);
            $zip = new \ZipArchive();
            $zip->open($fileName);
            $zip->extractTo($tmp);

            $file = $tmp . DIRECTORY_SEPARATOR . 'Module.php';

            if (!$config = $this->initConfig($file, true)) {
                throw new \RuntimeException('Error init module config');
            };

            if ($c = $this->getModuleConfigById($config->id)) {
                if ($this->upgrade($c, $config)) {
                    return true;
                }
            }

            $this->installFiles(dirname($file), $config);

            $this->clearCache();

            $config = $this->getModuleConfigById($config->id);

            $this->addModule($config);

            /** @var AppModuleInterface|Module $module */
            $module = \Yii::$app->getModule($config->id);

            if ($this->internalInstall($module)) {
                if ($this->isAutoActivate) {
                    $this->turnOn($config->id, $config);
                }
            }

        } catch (\Exception $exception) {
            $error = $exception->getMessage();
            return false;
        }

        return true;
    }

    public function uninstall($id, &$error)
    {
        try {
            $module = $this->loadModule($id, $config);
            if ($this->internalUnInstall($module)) {
                FileHelper::removeDirectory($config['path']);
            }
            $this->clearCache();
        } catch (\Exception $exception) {
            $error = $exception->getMessage();
            return false;
        }

        return true;
    }

    /**
     * @param string $method
     * @param string $eventBefore
     * @param string $eventAfter
     * @param Module|AppModuleInterface $module
     * @param object|null $target
     * @return bool
     */
    private function executeMethod($method, $eventBefore, $eventAfter, $module, $target = null)
    {
        $event = new ModuleEvent(['module' => $module]);

        $target = ($target) ? $target : $module;

        if (isset($eventBefore)) {
            $this->trigger(self::EVENT_BEFORE_INSTALL, $event);
        }

        if ($event->isValid) {

            try {
                $result = call_user_func([$target, $method]);
            } catch (UnknownMethodException $exception) {
                $r = new \ReflectionMethod($target, $method);
                $r->setAccessible(true);
                $result = $r->invoke($target);
            }

            if ($result) {
                if (isset($eventAfter)) {
                    $event->handled = false;
                    $this->trigger(self::EVENT_AFTER_INSTALL, $event);
                }
                return true;
            }
        }

        return false;
    }


    /**
     * @param $module \yii\base\Module|AppModuleInterface
     * @return bool
     */
    protected function internalUnInstall($module)
    {
        return $this->executeMethod('uninstall', self::EVENT_BEFORE_UNINSTALL,
            self::EVENT_AFTER_UNINSTALL, $module);
    }

    /**
     * @param $module \yii\base\Module|AppModuleInterface
     * @return bool
     */
    protected function internalInstall($module)
    {
        return $this->executeMethod('install', self::EVENT_BEFORE_INSTALL,
            self::EVENT_AFTER_INSTALL, $module);
    }

    /**
     * @param $module \yii\base\Module|AppModuleInterface
     * @param Config $config
     * @param string $state
     */
    protected function internalChangeState($module, $config, $state)
    {
        $this->executeMethod($state, self::EVENT_BEFORE_CHANGE_STATE,
            self::EVENT_AFTER_CHANGE_STATE,
            $module, $config);
    }

    /**
     * @param string $id
     * @param Config $config
     */
    public function turnOn($id, &$config)
    {
        $module = $this->loadModule($id, $config);
        $this->internalChangeState($module, $config, 'turnOn');
        $this->clearCache();
    }

    /**
     * @param string $id
     * @param Config $config
     */
    public function turnOf($id, &$config)
    {
        $module = $this->loadModule($id, $config);
        $this->internalChangeState($module, $config, 'turnOff');
        $this->clearCache();
    }

    /**
     * @param string $id
     * @param Config $config
     */
    public function toggle($id, &$config)
    {
        $module = $this->loadModule($id, $config);
        $this->internalChangeState($module, $config, 'toggle');
        $this->clearCache();
    }

    /**
     * @param $id
     * @param Config $config
     * @param $error
     * @return bool
     */
    public function reset($id, &$config, &$error)
    {
        try {
            $module = $this->loadModule($id, $config);

            if ($this->internalUnInstall($module)) {
                $this->internalInstall($module);
            }
            if ($this->isAutoActivate) {
                $this->turnOn($id, $config);
            }
            $this->clearCache();
        } catch (\Exception $exception) {
            $error = $exception->getMessage();
            return false;
        }

        return true;

    }

    /**
     * @param array $filter
     * @param bool $flushCache
     * @return array
     */
    public function getCategoriesArray($filter = [], $flushCache = false)
    {
        if ($flushCache) {
            $this->clearCache();
        }
        $models = $this->getFilteredClassesList($filter);

        if (empty($models)) {
            return [];
        }

        return array_map(function ($d) {
            return [
                'count' => count($d),
                'modules' => $d,
                'caption' => $d[0]->category
            ];
        }, ArrayHelper::index($models, null, 'category'));

    }


}