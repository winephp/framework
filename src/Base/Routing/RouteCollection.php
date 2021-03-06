<?php

namespace Base\Routing;

use Base\Routing\Route;
use Base\Support\Arr;


/**
* The Route Collection class
*
*
*/
class RouteCollection
{

    /**
    * The route collection storage
    *
    */
    protected $routes;


    /**
    * The route collection storage
    *
    */
    protected $allRoutes;


    /**
    * The route name list
    *
    */
    protected $nameList = [];


    /**
    * The route collection storage
    *
    */
    protected $requestMethods = [
        'GET','POST','PUT','DELETE','OPTIONS','HEAD'
    ];


    /**
    * $useRequestTypes (web | console | ajax)
    *
    * @var array
    */
    protected $requestTypes = ['web', 'console', 'ajax'];


    /**
    * The router patterns
    *
    * @var array
    */
    public $patterns = [
        'any' => '.*',
        'num' => '[0-9]+',
        'alphanum' => '[a-zA-Z0-9]+',
        'alpha' => '[a-zA-Z]+'
    ];


    /**
    * Used for groups
    *
    */
    protected $useMiddleware = [];
    protected $usePrefix = [];
    protected $useDomain = [];
    protected $useRequestTypes = [];


    /**
    * Get all routes in collection
    *
    */
    public function all()
    {
        return $this->routes;
    }


    /**
     * Get routes from the collection by method.
     *
     * @param  string|null  $method
     * @return array
     */
    public function get($method = null)
    {
        return is_null($method) ? $this->routes : Arr::get($this->routes, $method, []);
    }


    /**
    * Get all the routes by names
    *
    */
    public function getNames()
    {
        return $this->nameList;
    }


    /**
    * Match a route based on a request
    *
    */
    protected function matches($route, $params)
    {
        array_shift($params);

        $tempParameters = [];
        $p = 0;
        foreach($params as $index=>$match)
        {
            $p++;
            $tempParameters[$p] = $match;
        }

        $mparams = $route->getAction()['method_parameters'] ?? [];

        if (!empty($mparams))
        {
            $addParams = [];
            foreach($mparams as $param)
            {
                // get the parameter name (removal of the number symbol)
                $p = ($tempParameters[str_replace('$','',$param)]) ?? $param;
                // if the parameter name is blank
                if ($p === '') continue;
                // set the parameters in the correct order
                $addParams[] = $p;
            }

            $route->setParameters($addParams);
        }
        else
        {
            $route->setParameters($tempParameters);
        }

        return $route;
    }


    /**
    * Match a route based on a request
    *
    */
    public function match($uri, $method = 'GET')
    {
        $domain = app()->request->url->getHost();

        foreach($this->get($method) as $matchUri => $route)
        {
            $withDomain = $domain.'/'.$matchUri;
            $uriWithDomain = $domain.$uri;

            // check the route is allowed to run on the console.
            if (app()->request->isConsole()) {
                if (!in_array('console',$route->getRequestTypes())) continue;
            }

            // check the route is allowed to run on the ajax request.
            if (app()->request->isAjax()) {
                if (!in_array('ajax',$route->getRequestTypes())) continue;
            }

            // check that we can run this request on the "web" http
            if (!app()->request->isConsole() && !app()->request->isAjax()) {
                if (!in_array('web',$route->getRequestTypes())) continue;
            }

            // check if we are domain only routing
            if (preg_match('#^'. $matchUri .'$#i', $uriWithDomain, $params))
            {
                $route = $this->matches($route, $params);

                return $route;
            }

            // check all routing
            if (preg_match('#^'. $matchUri .'$#i', $uri, $params))
            {
                $route = $this->matches($route, $params);

                return $route;
            }
        }

        // add an error 404 route here...
        app()->response->setStatusCode(404);
        return $this->add($uri, config('router.errors', 'Error').'::index');
    }


    /**
    * Refresh the routes list
    *
    */
    public function refreshRouteList()
    {
        $this->nameList = [];

        foreach ($this->allRoutes as $route)
        {
            if ($route->getName())
            {
                $this->nameList[$route->getName()] = $route;
            }

            $routePatterns = $route->getPatterns();

            $routeUrl = $route->getDomain().$route->uri();
            $routeUrl = $this->setPathPatterns($routeUrl, $routePatterns);

            foreach($route->getMethods() as $method)
            {
                $this->routes[$method][$routeUrl] = $route;
            }
        }
    }


    /**
    * Replace the pattern names with REGEX patterns if found.
    *
    * @return array
    */
    protected function setPathPatterns($path, $routePatterns)
    {
        $routePatterns = array_merge($routePatterns, $this->patterns);

        if (!empty($routePatterns))
        {
            foreach($routePatterns as $name=>$pattern)
            {
                // double check we have our "(" group ")"
                if (!preg_match('|^\(.*?\)$|',$pattern)) $pattern = '('.$pattern.')';

                // inject the patterns on our paths
                $path = preg_replace('/\{'.$name.'\}/', $pattern, $path);
            }
        }

        return $path;
    }


    /**
    * Add a new route to the collection
    *
    * @param mixed $args
    * @return Base\Routing\Route
    */
    public function add(...$args)
    {
        if (count($args) > 1)
        {
            $methods = [];
            if (!empty(array_intersect((array) $args[0], $this->requestMethods))) $methods = (array) $args[0];

            $uri = $args[(!$methods) ? 0 : 1];
            $action = $args[(!$methods) ? 1 : 2] ?? null;
        }
        else {
            $methods = '';
            $uri = $args[0];
            $action = null;
        }

        $route = new Route($methods, $uri, $action);
        $route->domain(end($this->useDomain));
        $route->middleware(Arr::flatten($this->useMiddleware));
        $route->prefix($this->usePrefix);
        $route->setRequestTypes( (($this->useRequestTypes) ? end($this->useRequestTypes) : $this->requestTypes) );

        return $this->allRoutes[] = $route;
    }


    /**
    * Set route to CONSOLE only
    *
    * @param closure $fn
    */
    public function console($fn)
    {
        return $this->setRequestTypes(['console'], $fn);
    }


    /**
    * Set route to WEB only
    *
    * @param closure $fn
    */
    public function web($fn)
    {
        return $this->setRequestTypes(['web'], $fn);
    }


    /**
    * Set route to AJAX only
    *
    * @param closure $fn
    */
    public function ajax($fn)
    {
        return $this->setRequestTypes(['ajax'], $fn);
    }



    /**
    * Set the request types for thsi route
    *
    * @param mixed $type
    * @param closure $fn
    */
    protected function setRequestTypes($type, $fn)
    {
        $this->useRequestTypes[] = $type;

        $this->group($fn, function(){
            array_pop($this->useRequestTypes);
        });

        return $this;
    }



    /**
    * Setting up a group of routes,
    * when we're complete, we need to remove the last element of arrays
    *
    * @param closure $fn
    */
    protected function group($fn, $after = null)
    {
        if (is_callable($fn))
        {
            $fn();

            if (is_callable($after))
            {
                $after();
            }
        }
    }


    /**
    * Setting new patterns
    *
    * @param mixed $pattern
    */
    public function patterns($pattern)
    {
        $this->patterns = array_merge((array) $pattern, $this->patterns);
    }


    /**
    * Setting all the middleware for the group
    *
    * @param mixed $args
    * @return Base\Routing\RouteCollection
    */
    public function middleware(...$args)
    {
        $middleware = (array) $args[0];
        $group = $args[1] ?? null;

        $this->useMiddleware[] = $middleware;

        $this->group($group, function(){
            array_pop($this->useMiddleware);
        });

        return $this;
    }


    /**
    * Setting all the prefixes for a group
    *
    * @param mixed $args
    * @return Base\Routing\RouteCollection
    */
    public function prefix(...$args)
    {
        $prefix = $args[0];
        $group = $args[1] ?? null;

        // add the new prefix to the top of the array
        array_unshift($this->usePrefix, $prefix);

        $this->group($group, function(){
            array_shift($this->usePrefix);
        });

        return $this;
    }


    /**
    * Setting the domain for the group
    *
    * @param mixed $args
    * @return Base\Routing\RouteCollection
    */
    public function domain(...$args)
    {
        $domain = $args[0];
        $group = $args[1] ?? null;

        $this->useDomain[] = $domain;

        $this->group($group, function(){
            array_pop($this->useDomain);
        });

        return $this;
    }


    /**
    * Get a named route
    *
    * @param string $name
    */
    public function getNamed($name)
    {
        return $this->nameList[$name] ?? false;
    }


    /**
    * Get the uri path for a named route
    *
    * @param string $name
    * @param array $parameters
    */
    protected function path($name, $parameters = [])
    {
        $named = $this->getNamed($name);

        if (!$named) return false;

        $uri = $named->uri();

        foreach( (array) $parameters as $k => $v)
        {
            $uri = preg_replace('/\{'.$k.'\}/', $v, $uri);
        }

        return $uri;
    }


    /**
    * Redirect to a named route
    *
    * @param string $name
    * @param array $parameters
    */
    public function redirect($name, $parameters = [])
    {
        $uri = $this->path($name, $parameters);

        if ($uri)
        {
            return redirect($uri);
        }

        return false;
    }


    /**
    * Redirect to a named route
    *
    * @param string $name
    * @param array $parameters
    */
    public function to($name, $parameters = [])
    {
        return $this->redirect($name, $parameters);
    }


    /**
    * Check if a specifc named route is selected
    *
    * @param string $name
    * @return boolean
    */
    public function is($name)
    {
        return (bool) (app()->request->route->getName() == $name);
    }

}
