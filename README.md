Yii-Sentry
==========
Sentry component and log route for Yii framework.

### Requirements
* Yii Framework > 1.1.15 (Have not test any other frameworks)
* [Sentry Account](https://www.getsentry.com/) or Your own [Sentry server](http://sentry.readthedocs.org/en/latest/quickstart/)

### Download
```
composer require intersvyaz/yii-sentry
```

### Configure
main.php:
```php
...
'preload' => [
    ...
    'sentry',
],
...
'components' => [
    'sentry' => [
		'class' => Skillshare\YiiSentry\SentryComponent::class,
		'useRavenJs' => true,
		'ravenJsPlugins' => ['jquery'],
		'enabled' => !YII_DEBUG,
		'options' => [
		    'dsn' => 'https://X1:X2@host.com/2',
        ],
	],
    ...
    'log' => [
        'class' => 'CLogRouter',
        'routes' => [
             ...
            [
				'class' => Skillshare\YiiSentry\SentryLogRoute::class,
				'levels' => 'error, warning',
				'except' => 'exception.CHttpException.404, exception.CHttpException.400, exception.CHttpException.403',
			],
		],
    ],
],
...
```
