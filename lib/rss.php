<?php
declare(strict_types=1);

namespace Mastobot\Lib;

use Exception;
use Mastobot\Lib\Base;

/**
 * Rss class - retrieves xml/json feed and returns only new items since last fetch
 *
 * @param config:feed feed settings (url, root node, format, timestamp field name)
 * @param config:last_max_timestamp newest timestamp from last run
 *
 * @TODO:
 * - limit number of items from getFeed()
 */
class Rss extends Base
{
    /**
     * Get xml/json feed, return new items since last run
     *
     * @return object
     */
    public function getFeed()
    {
        $feed = $this->config->get('feed');

        $hCurl = curl_init();
        curl_setopt_array($hCurl, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_AUTOREFERER     => true,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => 5,
            CURLOPT_URL             => $feed->url,
        ]);

        $rssFeedRaw = curl_exec($hCurl);
        curl_close($hCurl);

        //DEBUG
        /*if (!is_file('feed.json')) {
            file_put_contents('feed.json', json_encode(json_decode($rssFeedRaw), JSON_PRETTY_PRINT));
        } else {
            $rssFeedRaw = file_get_contents('feed.json');
        }*/

        switch ($feed->format) {
            case 'xml':
                $rssFeed = simplexml_load_string($rssFeedRaw);
                break;
            case 'json':
            default:
                $rssFeed = json_decode($rssFeedRaw);
        }

        //trim object to relevant root node, if set
        if (!empty($feed->rootnode)) {
            $nodes = $this->getRssNodeField($rssFeed, $feed->rootnode);
        } else {
            $nodes = $rssFeed;
        }

        //limit to 10 latest items
        //TODO: take this from a setting, this is a quick hack
        if (count($nodes) > 10) {
            $nodes = array_slice($nodes, -10);
        }
        
        //truncate list of nodes to those with at least the max timestamp from last time
        $lastMaxTimestamp = $this->config->get('last_max_timestamp', 0);
        if ($lastMaxTimestamp) {
            foreach ($nodes as $key => $node) {

                //get value of timestamp field
                $timestamp = $this->getRssNodeField($node, $this->config->get('timestamp_field'));

                //remove node from list if timestamp is older than newest timestamp from last run
                if (is_numeric($timestamp) && $timestamp > 0 && $timestamp <= $lastMaxTimestamp) {
                    unset($nodes[$key]);
                }
            }
        }

        //get highest timestamp in list of nodes and save it
        $newestTimestamp = 0;
        if ($timestampField = $this->config->get('timestamp_field')) {
            foreach ($nodes as $item) {

                //get value of timestamp field
                $timestamp = $this->getRssNodeField($item, $timestampField);

                //save highest value of timestamp
                $newestTimestamp = (is_numeric($timestamp) && $timestamp > $newestTimestamp ? $timestamp : $newestTimestamp);
            }

            //save in settings
            if ($newestTimestamp > 0) {
                $this->config->set('last_max_timestamp', $newestTimestamp);
                //$this->oConfig->writeConfig(); //DEBUG
            }
        }

        return $nodes;
    }

    /**
     * Gets a subnode of node value from tree based on given 'node>subnode>etc' syntax arg
     *
     * @param object $node
     *
     * @return object
     *@throws Exception
     */
    private function getRssNodeField($node, string $field)
    {
        foreach (explode('>', $field) as $name) {
            if (isset($node->$name)) {
                $node = $node->$name;
            } else {
                throw new Exception(sprintf('Rss->getRssNodeField: node does not have %s field (full field: %s', $name, $field));
            }
        }

        return $node;
    }
}