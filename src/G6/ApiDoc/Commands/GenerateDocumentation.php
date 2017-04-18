<?php

namespace G6\ApiDoc\Commands;

use ReflectionClass;
use Illuminate\Console\Command;
use Mpociot\Reflection\DocBlock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use App\Nrb\NrbAuth;
use G6\ApiDoc\Documentarian;
use G6\ApiDoc\Postman\CollectionWriter;
use G6\ApiDoc\Generators\DingoGenerator;
use G6\ApiDoc\Generators\LaravelGenerator;
use G6\ApiDoc\Generators\AbstractGenerator;

class GenerateDocumentation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:generate
                            {--output=public/docs : The output path for the generated documentation}
                            {--uriPrefix= : The uri prefix to use for generation}
                            {--routePrefix= : The route prefix to use for generation}
                            {--routes=* : The route names to use for generation}
                            {--middleware= : The middleware to use for generation}
                            {--noResponseCalls : Disable API response calls}
                            {--noPostmanCollection : Disable Postman collection creation}
                            {--useMiddlewares : Use all configured route middlewares}
                            {--actAsUserID= : The user to use for API response calls}
                            {--data= : The user credentials to use for API response calls}
                            {--router=laravel : The router to be used (Laravel or Dingo)}
                            {--force : Force rewriting of existing routes}
                            {--bindings= : Route Model Bindings}
                            {--header=* : Custom HTTP headers to add to the example requests. Separate the header name and value with ":"}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate your API documentation from existing Laravel routes.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return false|null
     */
    public function handle()
    {
        if ($this->option('router') === 'laravel') {
            $generator = new LaravelGenerator();
            $generator->setUriPrefix($this->option('uriPrefix'));
        } else {
            $generator = new DingoGenerator();
        }

        $allowedRoutes = $this->option('routes');
        $routePrefix = $this->option('routePrefix');
        $middleware = $this->option('middleware');

        if ($routePrefix === null && ! count($allowedRoutes) && $middleware === null) {
            $this->error('You must provide either a route prefix or a route or a middleware to generate the documentation.');

            return false;
        }

        $generator->prepareMiddleware($this->option('useMiddlewares'));

        if ($this->option('router') === 'laravel') {
            $parsedRoutes = $this->processLaravelRoutes($generator, $allowedRoutes, $routePrefix, $middleware);
        } else {
            $parsedRoutes = $this->processDingoRoutes($generator, $allowedRoutes, $routePrefix, $middleware);
        }
        $parsedRoutes = collect($parsedRoutes)->groupBy('resource')->sort(function ($a, $b) {
            return strcmp($a->first()['resource'], $b->first()['resource']);
        });

        $this->writeMarkdown($parsedRoutes);
    }

    /**
     * @param  Collection $parsedRoutes
     *
     * @return void
     */
    private function writeMarkdown($parsedRoutes)
    {
        $outputPath = $this->option('output');
        $targetFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'index.md';
        $compareFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'.compare.md';

        $infoText = view('apidoc::partials.info')
            ->with('outputPath', ltrim($outputPath, 'public/'))
            ->with('showPostmanCollectionButton', ! $this->option('noPostmanCollection'));

        $parsedRouteOutput = $parsedRoutes->map(function ($routeGroup) {
            return $routeGroup->map(function ($route) {
                $route['output'] = (string) view('apidoc::partials.route')->with('parsedRoute', $route)->render();

                return $route;
            });
        });

        $frontmatter = view('apidoc::partials.frontmatter');
        /*
         * In case the target file already exists, we should check if the documentation was modified
         * and skip the modified parts of the routes.
         */
        if (file_exists($targetFile) && file_exists($compareFile)) {
            $generatedDocumentation = file_get_contents($targetFile);
            $compareDocumentation = file_get_contents($compareFile);

            if (preg_match('/<!-- START_INFO -->(.*)<!-- END_INFO -->/is', $generatedDocumentation, $generatedInfoText)) {
                $infoText = trim($generatedInfoText[1], "\n");
            }

            if (preg_match('/---(.*)---\\s<!-- START_INFO -->/is', $generatedDocumentation, $generatedFrontmatter)) {
                $frontmatter = trim($generatedFrontmatter[1], "\n");
            }

            $parsedRouteOutput->transform(function ($routeGroup) use ($generatedDocumentation, $compareDocumentation) {
                return $routeGroup->transform(function ($route) use ($generatedDocumentation, $compareDocumentation) {
                    if (preg_match('/<!-- START_'.$route['id'].' -->(.*)<!-- END_'.$route['id'].' -->/is', $generatedDocumentation, $routeMatch)) {
                        $routeDocumentationChanged = (preg_match('/<!-- START_'.$route['id'].' -->(.*)<!-- END_'.$route['id'].' -->/is', $compareDocumentation, $compareMatch) && $compareMatch[1] !== $routeMatch[1]);
                        if ($routeDocumentationChanged === false || $this->option('force')) {
                            if ($routeDocumentationChanged) {
                                $this->warn('Discarded manual changes for route ['.implode(',', $route['methods']).'] '.$route['uri']);
                            }
                        } else {
                            $this->warn('Skipping modified route ['.implode(',', $route['methods']).'] '.$route['uri']);
                            $route['modified_output'] = $routeMatch[0];
                        }
                    }

                    return $route;
                });
            });
        }

        $documentarian = new Documentarian();

        $markdown = view('apidoc::documentarian')
            ->with('writeCompareFile', false)
            ->with('frontmatter', $frontmatter)
            ->with('infoText', $infoText)
            ->with('outputPath', $this->option('output'))
            ->with('showPostmanCollectionButton', ! $this->option('noPostmanCollection'))
            ->with('parsedRoutes', $parsedRouteOutput);

        if (! is_dir($outputPath)) {
            $documentarian->create($outputPath);
        }

        // Write output file
        file_put_contents($targetFile, $markdown);

        // Write comparable markdown file
        $compareMarkdown = view('apidoc::documentarian')
            ->with('writeCompareFile', true)
            ->with('frontmatter', $frontmatter)
            ->with('infoText', $infoText)
            ->with('outputPath', $this->option('output'))
            ->with('showPostmanCollectionButton', ! $this->option('noPostmanCollection'))
            ->with('parsedRoutes', $parsedRouteOutput);

        file_put_contents($compareFile, $compareMarkdown);

        $this->info('Wrote index.md to: '.$outputPath);

        $this->info('Generating API HTML code');

        $documentarian->generate($outputPath);

        $this->info('Wrote HTML documentation to: '.$outputPath.'/public/index.html');

        if ($this->option('noPostmanCollection') !== true) {
            $this->info('Generating Postman collection');

            file_put_contents($outputPath.DIRECTORY_SEPARATOR.'collection.json', $this->generatePostmanCollection($parsedRoutes));
        }
    }

    /**
     * @return array
     */
    private function getBindings()
    {
        $bindings = $this->option('bindings');
        if (empty($bindings)) {
            return [];
        }
        $bindings = explode('|', $bindings);
        $resultBindings = [];
        foreach ($bindings as $binding) {
            list($name, $id) = explode(',', $binding);
            $resultBindings[$name] = $id;
        }

        return $resultBindings;
    }

    /**
     * @param $actAs
     */
    private function setUserToBeImpersonated($auth)
    {
        $userModel = config('auth.providers.users.model');
        if ($user = $userModel::find((int) $auth->id)) {
            $this->laravel['auth']->guard()->setUser($user);
        } else {
            $this->warn('Unable to authenticate: '.$auth->id);
        }
    }

    /**
     * @return mixed
     */
    private function getRoutes()
    {
        if ($this->option('router') === 'laravel') {
            return Route::getRoutes();
        } else {
            return app('Dingo\Api\Routing\Router')->getRoutes()[$this->option('routePrefix')];
        }
    }

    /**
     * @param AbstractGenerator  $generator
     * @param $allowedRoutes
     * @param $routePrefix
     *
     * @return array
     */
    private function processLaravelRoutes(AbstractGenerator $generator, $allowedRoutes, $routePrefix, $middleware)
    {
        $withResponse = $this->option('noResponseCalls') === false;
        $routes = $this->getRoutes();
        $bindings = $this->getBindings();
        $parsedRoutes = [];
        $deleteRoutes = [];
        $users = [];

        $headers = $this->option('header');
        $x_token_idx = sizeof($headers);

        $api_routes = config('documentor.routes');
        $api_users = config('documentor.users');

        foreach ($routes as $route) {
            $route_action = explode('\\', $route->getActionName());
            $route_name = $route_action[sizeof($route_action)-1];

            if (array_key_exists($route_name, $api_routes)) {
                $impersonation = new \stdClass();
                $impersonation->username = $api_routes[$route_name];
                $impersonation->password = $api_users[$impersonation->username]['password'];
                $impersonation->id = $api_users[$impersonation->username]['id'];
                $auth = NULL;

                if (array_key_exists($impersonation->username, $users)) {
                    $auth = $users[$impersonation->username];
                    $this->warn('User already added previously... continue...');
                } else {
                    $this->setUserToBeImpersonated($impersonation);

                    $this->info($route_name.': '.$impersonation->username);
                    if (NrbAuth::attempt($api_users[$impersonation->username])) {
                        $auth = $users[$impersonation->username] = $this->laravel['auth']->guard()->getUser();
                    }
                }
                if (!is_null($auth)) {
                    $this->info("Processing $route_name with token `".$auth->access_token->token."`");
                    $headers[$x_token_idx] = "X-Token: ".$auth->access_token->token;
                }
            }

            if (in_array($route->getName(), $allowedRoutes) || str_is($routePrefix, $generator->getUri($route)) || in_array($middleware, $route->middleware())) {
                if ($this->isValidRoute($route) && $this->isRouteVisibleForDocumentation($route->getAction()['uses'])) {
                    if (in_array('DELETE', $generator->getMethods($route))) {
                        $deleteRoutes[] = [$route, $bindings, $headers, $withResponse];
                    } else {
                        $parsedRoutes[] = $generator->processRoute($route, $bindings, $headers, $withResponse);
                    }

                    $this->info('Processed route: ['.implode(',', $generator->getMethods($route)).'] '.$generator->getFullUri($route));
                } else {
                    $this->warn('Skipping route: ['.implode(',', $generator->getMethods($route)).'] '.$generator->getFullUri($route));
                }
            }
        }

        for ($i = 0; $i < sizeof($deleteRoutes); $i++) {
            list($route, $bindings, $headers, $withResponse) = $deleteRoutes[$i];
            $parsedRoutes[] = $generator->processRoute($route, $bindings, $headers, $withResponse);

            $this->info('Processed route: ['.implode(',', $generator->getMethods($route)).'] '.$generator->getFullUri($route));
        }

        return $parsedRoutes;
    }

    /**
     * @param AbstractGenerator $generator
     * @param $allowedRoutes
     * @param $routePrefix
     *
     * @return array
     */
    private function processDingoRoutes(AbstractGenerator $generator, $allowedRoutes, $routePrefix, $middleware)
    {
        $withResponse = $this->option('noResponseCalls') === false;
        $routes = $this->getRoutes();
        $bindings = $this->getBindings();
        $parsedRoutes = [];
        foreach ($routes as $route) {
            if (empty($allowedRoutes) || in_array($route->getName(), $allowedRoutes) || str_is($routePrefix, $route->uri()) || in_array($middleware, $route->middleware())) {
                if ($this->isValidRoute($route) && $this->isRouteVisibleForDocumentation($route->getAction()['uses'])) {
                    $parsedRoutes[] = $generator->processRoute($route, $bindings, json_decode($this->option('data') || '{}'), $this->option('header'), $withResponse);
                    $this->info('Processed route: ['.implode(',', $route->getMethods()).'] '.$route->uri());
                } else {
                    $this->warn('Skipping route: ['.implode(',', $route->getMethods()).'] '.$route->uri());
                }
            }
        }

        return $parsedRoutes;
    }

    /**
     * @param $route
     *
     * @return bool
     */
    private function isValidRoute($route)
    {
        return ! is_callable($route->getAction()['uses']) && ! is_null($route->getAction()['uses']);
    }

    /**
     * @param $route
     *
     * @return bool
     */
    private function isRouteVisibleForDocumentation($route)
    {
        list($class, $method) = explode('@', $route);
        $reflection = new ReflectionClass($class);
        $comment = $reflection->getMethod($method)->getDocComment();
        if ($comment) {
            $phpdoc = new DocBlock($comment);

            return collect($phpdoc->getTags())
                ->filter(function ($tag) use ($route) {
                    return $tag->getName() === 'hideFromAPIDocumentation';
                })
                ->isEmpty();
        }

        return true;
    }

    /**
     * Generate Postman collection JSON file.
     *
     * @param Collection $routes
     *
     * @return string
     */
    private function generatePostmanCollection(Collection $routes)
    {
        $writer = new CollectionWriter($routes);

        return $writer->getCollection();
    }
}
