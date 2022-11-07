<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
//require_once __DIR__ . '/vendor/thecodingcompany/php-mastodon/autoload.php';

use theCodingCompany\Mastodon;

class DaylightSavings
{
    private const CLIENT_KEY = 'yqCuR626Y1-OggSQWDnjbrNTaO7N-ZVuYk-UOSzaMAk';
    private const CLIENT_SECRET = 'gkeoMkgWPvN5rI27RNpRCQNIgDw3gqjWPPhYMpCM8Uo';
    private const ACCESS_TOKEN = 'tNlStLtouxqaaEEuyWo7TOTWZK8yTeYAjANHWuq7UbA';

    private $mastodon;

    public function __construct()
    {
        $this->mastodon = new Mastodon();
        $this->mastodon->setCredentials([
            'client_id' => self::CLIENT_KEY,
            'client_secret' => self::CLIENT_SECRET,
            'bearer' => self::ACCESS_TOKEN,
        ]);
        $this->mastodon->setMastodonDomain('botsin.space');
    }

    public function run()
    {
        var_dump(get_class_methods($this->mastodon));
        var_dump(($this->mastodon->getUser()));
        //$this->mastodon->postStatus('Hello world!');
        die();
    }
}

(new DaylightSavings())->run();