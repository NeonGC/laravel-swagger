<?php

use RonasIT\Support\AutoDoc\DataCollectors\LocalDataCollector;

return [

    /*
    |--------------------------------------------------------------------------
    | Documentation Routes
    |--------------------------------------------------------------------------
    |
    | Route('s) which will return documentation
    */

    'routes' => ['/'],

    /*
    |--------------------------------------------------------------------------
    | Documentation Middlewares
    |--------------------------------------------------------------------------
    |
    | Middleware('s) required for documentation routes.
    */

    'middlewares' => ['auth'],

    /*
    |--------------------------------------------------------------------------
    | Info block
    |--------------------------------------------------------------------------
    |
    | Information fields
    */

    'info' => [

        /*
        |--------------------------------------------------------------------------
        | Documentation Template
        |--------------------------------------------------------------------------
        |
        | You can use your custom documentation view
        */

        'description' => 'swagger-description',
        'version' => '0.0.0',
        'title' => 'Name of Your Application',
        'termsOfService' => '',
        'contact' => [
            'email' => 'your@email.com'
        ],
        'license' => [
            'name' => '',
            'url' => ''
        ]
    ],
    'swagger' => [
        'version' => '2.0'
    ],

    /*
    |--------------------------------------------------------------------------
    | Base API path
    |--------------------------------------------------------------------------
    |
    | Base path for API routes. If config is set, all routes which starts from
    | this value will be grouped.
    */

    'basePath' => '/',
    'schemes' => [],
    'definitions' => [],

    /*
    |--------------------------------------------------------------------------
    | Security Library
    |--------------------------------------------------------------------------
    |
    | Library name, which used to secure the project.
    | Available values: "jwt", "laravel", "token", "null"
    */

    'security' => '',
    'defaults' => [

        /*
        |--------------------------------------------------------------------------
        | Default descriptions of code statuses
        |--------------------------------------------------------------------------
        */

        'code-descriptions' => [
            '200' => 'Operation successfully done',
            '204' => 'Operation successfully done',
            '404' => 'This entity not found'
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Collector Class
    |--------------------------------------------------------------------------
    |
    | Class of data collector, which will collect and save documentation
    | It can be your own data collector class which should be inherited from
    | RonasIT\Support\AutoDoc\Interfaces\DataCollectorInterface interface,
    | or our data collectors from next packages:
    |
    | ronasit/laravel-remote-data-collector
    |
    | If config not set, will be using LocalDataCollector::class
    */

    'data_collector' => LocalDataCollector::class,

     /*
     |--------------------------------------------------------------------------
     | Swagger documentation visibility environments list
     |-------------------------------------------------------------------------- 
     |
     | The list of environments in which auto documentation will be displaying
     */
    'display_environments' => [
        'local',
        'development',
    ],
];
