<?php namespace Base;

use Exception;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidPathException;
use Base\Routing\Router;
use Base\Http\Request;
use Base\Http\Response;
use Base\Support\Collection;
use Base\Support\Filesystem;

use Base\Routing\Providers\RouterServiceProvider;
use Base\Http\Providers\HttpServiceProvider;


/**
* The Application Class
*
*/
class Application
{
    /**
    * The Version of BasePHP
    *
    */
    const VERSION = '1.2.0';


    /**
    * The current application instance
    *
    * @var static
    */
    protected static $instance;


    /**
    * The application services instances
    *
    * @var array
    */
    protected $instances = [];


    /**
    * console mode from "base"
    *
    * @var bool
    */
    protected $baseConsole = false;


    /**
    * Core Service Poviders to be loaded.
    *
    * @var array
    */
    protected $providers = [
        RouterServiceProvider::class,
        HttpServiceProvider::class
    ];


    /**
    * Loaded service probiders
    *
    * @var array
    */
    protected $activeProviders = [];


    /**
    * Instantiate the Application
    *
    * @see public/index.php
    */
    public function __construct( $rootPath = '' )
    {
        $rootPath = rtrim($rootPath, '\/');

        $this->register('config', $config = new Collection([
            'path' => [
            	'root' => $rootPath,
            	'app' => $rootPath.DIRECTORY_SEPARATOR.'app',
            	'config' => $rootPath.DIRECTORY_SEPARATOR.'config',
            	'views' => $rootPath.DIRECTORY_SEPARATOR.'views',
                'storage' => $rootPath.DIRECTORY_SEPARATOR.'storage',
            	'routes' => $rootPath.DIRECTORY_SEPARATOR.'routes'
            ]
        ]));
    }


    /**
    * Get the version of the application.
    *
    * @return string
    */
    public function version()
    {
        return static::VERSION;
    }


    /**
    * Set the Base Console Mode
    *
    * @return bool
    */
    public function setBaseConsoleMode($set = false)
    {
        $this->baseConsole = $set;
    }


    /**
    * Get the Base Console Mode
    *
    * @return bool
    */
    public function getBaseConsoleMode()
    {
        return $this->baseConsole;
    }


    /**
    * Begin our application
    *
    * @see public/index.php
    */
    public function initialize()
    {
        self::setInstance($this);

        $this->setDotEnv();
        $this->setConfigurations();
        $this->setAppSettings();

        // build the service provider list
        $this->buildServiceProviders();

        // register and boot the service providers
        $this->registerServiceProviders();
        $this->bootServiceProviders();

        // create the storage directory "storage/framework"
        $this->storageDirectory();

        // run our application
        $this->run();
    }


    /**
    * Check if storage/framework exists.
    *
    */
    protected function storageDirectory()
    {
        if (Filesystem::isWritable(storage_path()))
        {
            $framework = storage_path('framework');

            if (!Filesystem::isDirectory($framework))
            {
                Filesystem::makeDirectory($framework, 0775, true);
            }
        }
        else
        {
            throw new Exception('Storage Path: '.storage_path().' is not writable.');
        }
    }


    /**
    * Run the application
    *
    */
    protected function run()
    {
        if ($this->request->isConsole())
        {
            // keep the script running even when console goes away.
            ignore_user_abort(true);
        }

        $this->router->match( $this->request );

        // do all the magic...
        $this->router->run();

        // let's make a few modifications before we send to the browser
        if ($body = $this->response->getBody())
        {
            $currentUsage = memory_get_usage();

            $time = (float) number_format(microtime(true) - APP_START, 4);
            $memory = format_bytes($currentUsage,3);

            $body = str_replace('{APP_TIME}', $time, $body);
            $body = str_replace('{APP_MEMORY}', $memory, $body);

            $this->response->setBody($body);
        }

        // send the response to the browser
        $this->response->send();
    }


    /**
    * Set the DotEnv settings (variables from ".env")
    *
    */
    protected function setDotEnv()
    {
        try
        {
            (new Dotenv(path('root'),'.env'))->load();
        }
        catch (InvalidPathException $e)
        {

        }
    }


    /**
    * Set the application paths and load up configuration files
    *
    */
    protected function setConfigurations()
    {
        if ($files = $this->getFiles('config'))
        {
            foreach ($files as $key => $filename)
            {
                $this->config->set(basename($filename, '.php'), require path('config').DIRECTORY_SEPARATOR.($filename));
            }
        }
    }


    /**
    * Load Service Providers from the configs
    *
    */
    protected function buildServiceProviders()
    {
        foreach($this->config as $configName)
        {
            if (isset($configName['providers']) && is_array($configName['providers']))
            {
                $this->providers = array_merge($this->providers, $configName['providers']);
            }
        }
    }


    /**
    * "register" and Load Service Providers.
    * Save all the "active" providers within $activeProviders
    */
    protected function registerServiceProviders()
    {
        foreach($this->providers as $provider)
        {
            // check if the provider class exists
            if (class_exists($provider))
            {
                // register this service provider, and Instantiate it.
                $service = new $provider($this);

                // check if the service provider has a boot method.
                if (method_exists($service, 'register')) {
                    $service->register();
                }

                // save the active provider so that we can call it later.
                $this->activeProviders[$provider] = $service;
            }
        }
    }


    /**
    * "boot" the Service Poviders
    * After we have registered all the providers,
    * We boot them up with any additional logic
    */
    protected function bootServiceProviders()
    {
        foreach($this->activeProviders as $provider)
        {
            // check if the service provider has a boot method.
            if (method_exists($provider, 'boot')) {
                $provider->boot();
            }
        }
    }


    /**
    * get all the configuration files located in the app/config
    *
    * @return array
    */
    public function getFiles($dir)
    {
        return Filesystem::getAll(path($dir), 'php');
    }


    /**
    * Set the app settines for internal php configurations
    *
    */
    protected function setAppSettings()
    {
        // set the application time zone
        date_default_timezone_set($this->config->get('app.timezone','UTC'));

        // set the application character encoding
        mb_internal_encoding($this->config->get('app.encoding','UTF-8'));
    }


    /**
    * Register an instance to share within this application
    *
    * @param  string  $name
    * @param  mixed   $instance
    * @return mixed
    */
    public function register($name, $instance)
    {
        return $this->instances[$name] = $instance;
    }


    /**
    * Get the service providers
    *
    * @return array
    */
    public function getServiceProviderList()
    {
        return $this->providers;
    }


    /**
    * Get active service provider list
    *
    * @return array
    */
    public function getActiveServiceProviderList()
    {
        return array_keys($this->activeProviders);
    }


    /**
    * Get the current instance
    *
    * @return static
    */
    public static function getInstance()
    {
        return static::$instance;
    }


    /**
    * Set the application instance
    *
    * @return static
    */
    public static function setInstance($app)
    {
        return static::$instance = $app;
    }


    /**
    * Get an instance
    *
    * @param  string  $key
    * @return mixed
    */
    public function __get($key)
    {
        return $this->instances[$key] ?? null;
    }

}
