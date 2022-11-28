<?php
declare(strict_types=1);

namespace Mastobot\Lib;

use Mastobot\Lib\Logger;
use stdClass;

/**
 * Config class - store and retrieve persistent settings
 */
class Config
{
    private $logger;
    private $username;
    private $oDefaultSettings;
    private $oSettings;

    public function __construct(string $username)
    {
        $this->username = $username;
        $this->logger = new Logger();
    }

    /**
     * Load settings for given mastodon username from .json file
     */
    public function load(): bool
    {
        $defaultSettingsFile = MYPATH . '/default.json';
        $userSettingsFile = MYPATH . '/' . $this->username . '.json';

        //load default settings
        if (is_file($defaultSettingsFile)) {
            $this->oDefaultSettings = json_decode(file_get_contents($defaultSettingsFile));
            $this->checkForJsonError();
        }

        //load bot settings and merge
        if (is_file($userSettingsFile)) {
            $this->oSettings = json_decode(file_get_contents($userSettingsFile));
            $this->checkForJsonError();
        } else {
            $this->halt(sprintf('Config json file not found for username %s! Halting.', $this->username));
        }

        return !is_null($this->oSettings);
    }

    /**
     * Get value of config setting
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $name, $default = false)
    {
        if (in_array($name, ['sUsername', 'username'])) {
            return $this->username;
        }

        return $this->oSettings->$name ?? $this->oDefaultSettings->$name ?? $default;
    }

    /**
     * Set config setting (recursive, can be multiple levels deep)
     * @TODO: PHP7 syntax for function arguments
     *
     * @param string(s) keys
     * @param string value
     */
    public function set(): void
    {
        //get all func arguments
        $aArgs = func_get_args();

        //take out value
        $mValue = array_pop($aArgs);

        //take out property we want to set to above value
        $oProp = array_pop($aArgs);

        //recursively get node to set property of
        //NB: no & operator needed since these are objects
        $oNode = $this->oSettings;
        foreach ($aArgs as $sSubnode) {
            if (!isset($oNode->$sSubnode)) {
                $oNode->$sSubnode = new stdClass();
            }

            $oNode = $oNode->$sSubnode;
        }

        //set property to new value
        $oNode->$oProp = $mValue;

        //$this->writeConfig();
    }

    /**
     * Handle json error
     */
    private function checkForJsonError(): void
    {
        $jsonErrNo = json_last_error();
        if (!$jsonErrNo) {
            return;
        }

        $jsonErrMsg = json_last_error_msg();
        $this->halt(sprintf('Error reading JSON file for %s: %s (%s)', $this->username, $jsonErrNo, $jsonErrMsg));
    }

    private function halt(string $message): void
    {
        $this->logger->output($message);
        $this->logger->write(1, $message);
        exit(1);
    }

    /**
     * Write current config to disk
     */
    public function writeConfig(): void
    {
        if (isset($this->username)) {
            file_put_contents(MYPATH . '/' . strtolower($this->username) . '.json', json_encode($this->oSettings, JSON_PRETTY_PRINT));
        }
    }

    public function __destruct()
    {
        $this->writeConfig();
    }
}