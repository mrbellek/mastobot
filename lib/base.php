<?php
declare(strict_types=1);

namespace Mastobot\Lib;

require_once(MYPATH . '/vendor/autoload.php');
require_once('base.inc.php');

use Mastobot\Lib\Logger;
use Mastobot\Lib\Config;
use theCodingCompany\Mastodon;

//little debug function
function dd($a): void
{
    print_r($a);
    exit(0);
}

/**
 * Base lib class - creates Mastodon API object and logger, basic setter
 */
class Base
{
    protected $config;
    protected $mastodon;
    protected $logger;

    public function __construct(Config $config)
    {
        $this->config = $config;

        $this->mastodon = new Mastodon();
        $this->mastodon->setCredentials([
            'client_id' => CLIENT_KEY,
            'client_secret' => CLIENT_SECRET,
            'bearer' => ACCESS_TOKEN,
        ]);
        $this->mastodon->setMastodonDomain('botsin.space');

        $this->logger = new Logger();
    }

    /**
     * @param mixed $value
     */
    public function set(string $name, $value): self
    {
        $this->$name = $value;

        return $this;
    }
}