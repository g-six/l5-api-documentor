<?php

namespace G6\ApiDoc;

use Illuminate\Support\ServiceProvider;
use G6\ApiDoc\Commands\UpdateDocumentation;
use G6\ApiDoc\Commands\GenerateDocumentation;

class ApiDocGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views/', 'apidoc');
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'apidoc');

        $this->publishes([
            __DIR__.'/../../resources/lang' => $this->resource_path('lang/vendor/apidoc'),
            __DIR__.'/../../resources/views' => $this->resource_path('views/vendor/apidoc'),
        ]);
    }

    /**
     * Register the API doc commands.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('apidoc.generate', function () {
            return new GenerateDocumentation();
        });
        $this->app->singleton('apidoc.update', function () {
            return new UpdateDocumentation();
        });

        $this->commands([
            'apidoc.generate',
            'apidoc.update',
        ]);
    }

    /**
     * Return a fully qualified path to a given file.
     *
     * @param string $path
     *
     * @return string
     */
    public function resource_path($path = '')
    {
        return app()->basePath().'/resources'.($path ? '/'.$path : $path);
    }
}
