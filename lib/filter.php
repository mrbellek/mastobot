<?php
declare(strict_types=1);

namespace Mastobot\Lib;

use Mastobot\Lib\Base;

/**
 * Filter class, use several settings and rules to filter unwanted tweets
 *
 * @param config:filters
 * @param config:dice_values
 */
class Filter extends Base
{
    private $searchFilters = [];
    private $usernameFilters = [];
    private $diceValues = [];

    //hardcoded search filters
    private $defaultFilters = [
        //quote (instead of retweet)
        '"@',
         //smart quote  (chr(147)
        'ô@',
        '@',
        //mangled smart quote
        'â@',
        //more smart quote “
        '“@',
    ];

    //default probability values
    private $defaultDiceValues = [
        'media'     => 1.0,
        'urls'      => 0.8,
        'mentions'  => 0.5,
        'base'      => 0.7,
    ];

    /**
     * Get filters from config and split out
     */
    public function setFilters(): self
    {
        if ($filters = $this->config->get('filters')) {
            $this->searchFilters   = array_merge($this->defaultFilters, (!empty($filters->tweet) ? $filters->tweet : []));
            $this->usernameFilters = array_merge(['@' . $this->config->get('sUsername')], (!empty($filters->username) ? $filters->username : []));
        }
        if ($diceValues = $this->config->get('dice_values')) {
            $this->diceValues      = array_merge($this->defaultDiceValues, (array) $diceValues);
        }

        return $this;
    }

    /**
     * Apply filters to tweets, return remaining tweets
     */
    public function filter(array $tweets): array
    {
        foreach ($tweets as $i => $oTweet) {

            //replace shortened links
            $oTweet = $this->expandUrls($oTweet);

            if (!$this->applyFilters($oTweet) ||
                !$this->applyUsernameFilters($oTweet) ||
                !$this->rollDie($oTweet)) {

                unset($tweets[$i]);
            }
        }

        return $tweets;
    }

    /**
     * Apply textual filters to tweet
     *
     * @param object|string $tweet
     */
    private function applyFilters($tweet): bool
    {
        foreach ($this->searchFilters as $filter) {
            if (is_object($tweet)) {
                if (strpos(strtolower($tweet->text), $filter) !== false) {
                    $this->logger->output('<b>Skipping tweet because it contains "%s"</b>: %s', $filter, str_replace("\n", ' ', $tweet->text));

                    return false;
                }
            } elseif (strpos(strtolower($tweet), $filter) !== false) {
                $this->logger->output('<b>Skipping tweet because it contains "%s"</b>: %s', $filter, str_replace("\n", ' ', $tweet));

                return false;
            }
        }

        return true;
    }

    /**
     * Apply username filters to tweet
     *
     * @param object $tweet
     * @return bool
     */
    private function applyUsernameFilters($tweet)
    {
        if (is_object($tweet)) {
            foreach ($this->usernameFilters as $username) {
                if (strpos(strtolower($tweet->user->screen_name), $username) !== false) {
                    $this->logger->output('<b>Skipping tweet because username contains "%s"</b>: %s', $username, $tweet->user->screen_name);
                    return false;
                }
                if (preg_match('/@\S*' . $username . '/', $tweet->text)) {
                    $this->logger->output('<b>Skipping tweet because mentioned username contains "%s"</b>: %s', $username, $tweet->text);
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Apply probability values to tweet (more interesting tweets have more chance of passing
     *
     * @param object $tweet
     */
    private function rollDie($tweet): bool
    {
        if (!is_object($tweet)) {
            return true;
        }

		//regular tweets are better than mentions - medium probability
		$probability = $this->diceValues['base'];

		if (!empty($tweet->entities->media) && count($tweet->entities->media) > 0
			|| strpos('instagram.com/p/', $tweet->text) !== false
			|| strpos('vine.co/v/', $tweet->text) !== false) {

			//photos/videos are usually funny - certain
			$probability = $this->diceValues['media'];

		} elseif (!empty($tweet->entities->urls) && count($tweet->entities->urls) > 0) {
			//links are ok but can be porn - high probability
			$probability = $this->diceValues['urls'];

		} elseif (strpos('@', $tweet->text) === 0) {
			//mentions tend to be 'remember that time' stories or insults - low probability
			$probability = $this->diceValues['mentions'];
		}

		//compare probability (0.0 to 1.0) against random number
		$random = mt_rand() / mt_getrandmax();
		if ($random > $probability) {
			$this->logger->output('<b>Skipping tweet because the dice said so</b>: %s', str_replace("\n", ' ', $tweet->text));
			return false;
		}

		return true;
    }

    /**
     * Replace shortened t.co urls in tweet with expanded urls
     *
     * @param object $tweet
     * @return object
     */
    private function expandUrls($tweet)
    {
		//check for links/photos
		if (is_object($tweet) && strpos($tweet->text, 'https://t.co') !== false) {
            foreach($tweet->entities->urls as $url) {
                $tweet->text = str_replace($url->url, $url->expanded_url, $tweet->text);
            }
		}

		return $tweet;
    }
}