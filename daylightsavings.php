<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/mrbellek/php-mastodon/autoload.php';
require_once __DIR__ . '/daylightsavings.inc.php';

use Mastobot\Lib\Auth;
use Mastobot\Lib\Config;
use Mastobot\Lib\Database;
use Mastobot\Lib\Format;
use Mastobot\Lib\Logger;
use Mastobot\Lib\Toot;

class DaylightSavings
{
    private $username = 'DaylightSavings';
    private $logger;
    private $config;
    private $db;

    public function __construct()
    {
        $this->logger = new Logger();
    }

    public function run()
    {
        $this->config = new Config($this->username);
        if (!$this->config->load()) {
            $this->halt(sprintf('Loading config for "%s" failed', $this->username));
        }

        $auth = new Auth($this->config);
        if (!$auth->verifyUser($this->username)) {
            $this->halt(sprintf('Verifying username "%s" failed', $this->username));
        }

        $this->db = new Database($this->config);
        if (!$this->db->connect()) {
            $this->halt('Failed to connect to database');
        }

        $posts = $this->checkDST();
        if (!empty($posts)) {
            $this->logger->output('Posting %d messages...', count($posts));
            (new Toot($this->config))->post($posts);
        }

        $this->logger->output('Done!');
    }

    private function checkDST(): array
    {
        $this->logger->output('Checking for DST start..');
        $toots = [];

        $today = strtotime(gmdate('Y-m-d'));

        $delays = [
            'today' => 0,
            'tomorrow' => 24 * 3600,
            'next week' => 7 * 24 * 3600,
        ];

        //check if any of the countries are switching to DST (summer time) either today, tomorrow or next week
        foreach ($delays as $delayName => $delaySeconds) {
            if ($groups = $this->checkDSTStart($today + $delaySeconds)) {
                $toots = array_merge($toots, $this->formatTweetDST('start', $groups, $delayName));
                $this->logger->output('- %s groups start DST %s!', count($groups), $delayName);
            } else {
                $this->logger->output('- No groups start DST %s.', $delayName);
            }
        }

        $this->logger->output('Checking for DST end..');

        //check if any of the countries are switching from DST (winter time) today, tomorrow or next week
        foreach ($delays as $delayName => $delaySeconds) {
            if ($groups = $this->checkDSTEnd($today + $delaySeconds)) {
                $toots = array_merge($toots, $this->formatTweetDST('end', $groups, $delayName));
                $this->logger->output('- %s groups exit DST %s!', count($groups), $delayName);
            } else {
                $this->logger->output('- No groups exit DST %s.', $delayName);
            }
        }

        return $toots;
    }

    //check if DST starts (summer time start) for any of the countries
    private function checkDSTStart($timestamp): array
    {
        $dstStartGroups = [];

        //check groups
        foreach ($this->getAllGroups() as $group) {
            if (strtolower($group['shortname']) != 'no dst') {

                //convert 'last sunday of march 2014' to timestamp
                $dstStartTimestamp = strtotime(sprintf('%s %s', $group['start'], date('Y')));

                if ($dstStartTimestamp == $timestamp) {

                    //DST will start here
                    $group['includes'] = $this->getGroupCountries($group['id']);
                    $dstStartGroups[$group['shortname']] = $group;
                }
            }
        }

        //check countries without group
        foreach ($this->getUngroupedCountries() as $country) {
            $dstStartTimestamp = strtotime(sprintf('%s %s', $country['start'], date('Y')));
            if ($dstStartTimestamp == $timestamp) {
                $dstStartGroups[$country['name']] = $country;
            }
        }

        return $dstStartGroups ?: [];
    }

    //check if DST ends (winter time start) for any of the countries
    private function checkDSTEnd($timestamp): array
    {
        $dstEndGroups = [];

        //check groups
        foreach ($this->getAllGroups() as $group) {
            if (strtolower($group['shortname']) != 'no dst') {

                //convert 'last sunday of march 2014' to timestamp
                $dstEndTimestamp = strtotime(sprintf('%s %s', $group['end'], date('Y')));

                if ($dstEndTimestamp == $timestamp) {

                    //DST will end here
                    $group['includes'] = $this->getGroupCountries($group['id']);
                    $dstEndGroups[$group['shortname']] = $group;
                }
            }
        }

        //check countries without group
        foreach ($this->getUngroupedCountries() as $country) {
            $dstEndTimestamp = strtotime(sprintf('%s %s', $country['end'], date('Y')));
            if ($dstEndTimestamp == $timestamp) {
                $dstEndGroups[$country['name']] = $country;
            }
        }

        return $dstEndGroups ?: [];
    }

    public function formatTweetDST($event, $groups, $delay): array
    {
        $posts = [];
        foreach ($groups as $shortName => $group) {
            if ($shortName != 'No dst') {
                $name = ($group['name'] ?? ucwords($shortName));

                $posts[] = (new Format($this->config))->format((object) [
                    'event' => $event . 's',   //start[s] or end[s]
                    'delay' => $delay,         //today/tomorrow/next week
                    'countries' => $name,      //group name or country name
                ]);
            }
        }

        return $posts;
    }

    private function getAllGroups(): array
    {
        return $this->db->query('
            SELECT g.*, GROUP_CONCAT(e.exclude SEPARATOR "|") AS excludes
            FROM dst_group g
            LEFT JOIN dst_exclude e ON e.group_id = g.id
            GROUP BY g.id'
        );
    }

    private function getGroupCountries($groupId): array
    {
        return $this->db->query('
            SELECT c.*, GROUP_CONCAT(ca.alias SEPARATOR "|") AS aliases
            FROM dst_country c
            LEFT JOIN dst_country_alias ca ON ca.country_id = c.id
            WHERE c.group_id = :group_id
            GROUP BY c.id',
            [':group_id' => $groupId]
        );
    }

    private function getUngroupedCountries(): array
    {
        return $this->db->query('
            SELECT c.*, GROUP_CONCAT(ca.alias SEPARATOR "|") AS aliases
            FROM dst_country c
            LEFT JOIN dst_country_alias ca ON ca.country_id = c.id
            WHERE c.group_id IS NULL
            GROUP BY c.id'
        );
    }

    private function halt(string $message): void
    {
        $this->logger->output($message);
        $this->logger->write(1, $message);
        exit(1);
    }
}

(new DaylightSavings())->run();