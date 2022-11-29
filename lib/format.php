<?php
declare(strict_types=1);

namespace Mastobot\Lib;

use Mastobot\Lib\Base;
use Mastobot\Custom\Imgur;

/**
 * Format class - formats objects into a tweet according to settings
 *
 * TODO: the tweet_vars.x.attach_image field is not checked, instead hardcoding it based on content type
 * TODO: any attached content isn't removed from the tweet
 * @TODO: https://imgur.com/tdXKAgN isn't correctly parsed
 * @TODO: optionally add ellipsis when truncating field
 *
 * @param config:source - database/rss where record came from, needed for handling format settings
 * @param config:max_tweet_length
 * @param config:short_url_length
 * @param config:tweet_vars - variables present in tweet and their values
 * @param config:format - unformatted tweet string
 * @param config:allow_mentions - allow tweets to mention other users
 */
class Format extends Base
{
    private $attachFile;
    private $attachment;

    /**
     * Format record object as a database or rss item (wrapper)
     *
     * @param object $record
     */
    public function format($record): string
    {
        if (is_array($record)) {
            $record = (object) $record;
        }

        switch ($this->config->get('source')) {
            default:
            case 'database':
                return $this->db_format((array)$record);

            case 'rss':
            case 'json':
            case 'other':
                return $this->rss_format($record);
        }
    }

    /**
     * Format record object as an rss item according to tweet settings
     *
     * @param object $record
     */
    public function rss_format($record): string
    {
        $maxTweetLength = $this->config->get('max_tweet_lengh', 280);
        $shortUrlLength = $this->config->get('short_url_length', 23);

        //format message according to format in settings, and return it
        $tweetVars = $this->config->get('tweet_vars');
        $tweet = $this->config->get('format');

        //replace all non-truncated fields
        foreach ($tweetVars as $tweetVar) {
            if (empty($tweetVar->truncate)) {
                $tweet = str_replace($tweetVar->var, $this->getRssValue($record, $tweetVar), $tweet);
            }
        }

        //disable mentions if needed
        if (!$this->config->get('allow_mentions', false)) {
            $tweet = str_replace('@', '@\\', $tweet);
        }

        //determine maximum length left over for truncated field (links are shortened to t.co format of max 22 chars)
        $tempTweet = preg_replace('/http:\/\/\S+/', str_repeat('x', $shortUrlLength), $tweet);
        $tempTweet = preg_replace('/https:\/\/\S+/', str_repeat('x', $shortUrlLength + 1), $tempTweet);
        $truncateLimit = $maxTweetLength - strlen($tempTweet);

        //replace truncated field
        foreach ($tweetVars as $tweetVar) {
            if (!empty($tweetVar->truncate)) {

                //placeholder will get replaced, so add that to char limit
                $truncateLimit += strlen($tweetVar->var);

                //get text to replace placeholder with
                $text = html_entity_decode($this->getRssValue($record, $tweetVar), ENT_QUOTES, 'UTF-8');

                //disable mentions if needed
                if (!$this->config->get('allow_mentions', false)) {
                    $text = str_replace('@', '@\\', $text);
                }

                //get length of text with url shortening
                $tempText = preg_replace('/http:\/\/\S+/', str_repeat('x', $shortUrlLength), $text);
                $tempText = preg_replace('/https:\/\/\S+/', str_repeat('x', $shortUrlLength + 1), $tempText);
                $textLength = strlen($tempText);

                //if text with url shortening falls under limit, keep it - otherwise truncate
                if ($textLength <= $truncateLimit) {
                    $tweet = str_replace($tweetVar->var, $text, $tweet);
                } else {
                    $tweet = str_replace($tweetVar->var, substr($text, 0, $truncateLimit), $tweet);
                }

                //only 1 truncated field allowed
                break;
            }
        }

        return trim($tweet);
    }

    /**
     * Format record object as a database record, according to tweet settings
     */
    public function db_format(array $record): string
    {
        $maxTweetLength = $this->config->get('max_tweet_lengh', 280);
        $shortUrlLength = $this->config->get('short_url_length', 23);

        //format message according to format in settings, and return it
        $tweetVars = $this->config->get('tweet_vars', []);
        $tweet = $this->config->get('format');

        //replace all non-truncated fields
        foreach ($tweetVars as $tweetVar) {
            if (empty($tweetVar->truncate)) {
                $tweet = str_replace($tweetVar->var, $record->{$tweetVar->recordfield}, $tweet);
            }
        }

        //disable mentions if needed
        if (!$this->config->get('allow_mentions', false)) {
            $tweet = str_replace('@', '#', $tweet);
        }

        //determine maximum length left over for truncated field (links are shortened to t.co format of max 22 chars)
        $tempTweet = preg_replace('/http:\/\/\S+/', str_repeat('x', $shortUrlLength), $tweet);
        $tempTweet = preg_replace('/https:\/\/\S+/', str_repeat('x', $shortUrlLength + 1), $tempTweet);
        $truncateLimit = $maxTweetLength - strlen($tempTweet);

        //replace truncated field
        foreach ($tweetVars as $tweetVar) {
            if (!empty($tweetVar->truncate)) {

                //placeholder will get replaced, so add that to char limit
                $truncateLimit += strlen($tweetVar->var);

                //get text to replace placeholder with
                $sText = html_entity_decode($record->{$tweetVar->recordfield}, ENT_QUOTES, 'UTF-8');

                //disable mentions if needed
                if (!$this->config->get('allow_mentions', false)) {
                    $sText = str_replace('@', '#', $sText);
                }

                //get length of text with url shortening
                $tempText = preg_replace('/http:\/\/\S+/', str_repeat('x', $shortUrlLength), $sText);
                $tempText = preg_replace('/https:\/\/\S+/', str_repeat('x', $shortUrlLength + 1), $tempText);
                $textLength = strlen($tempText);

                //if text with url shortening falls under limit, keep it - otherwise truncate
                if ($textLength <= $truncateLimit) {
                    $tweet = str_replace($tweetVar->var, $sText, $tweet);
                } else {
                    $tweet = str_replace($tweetVar->var, substr($sText, 0, $truncateLimit), $tweet);
                }

                //only 1 truncated field allowed
                break;
            }
        }

        return $tweet;
    }

    /**
     * Get value of variable from rss object according to tweet setting object
     *
     * @param object $record
     * @param object $value
     * @return mixed
     */
    private function getRssValue($record, $value)
    {
        if (strpos($value->value, 'special:') === 0) {
            return $this->getRssSpecialValue($record, $value);
        }

        $return = $record;
        foreach (explode('>', $value->value) as $node) {
            if (isset($return->$node)) {
                $return = $return->$node;
            } else {
                $return = (!empty($value->default) ? $value->default : '');
                break;
            }
        }

        //if a regex is set, apply that to return value and return only first captured match
        if (!empty($value->regex) && preg_match($value->regex, $return, $aMatches)) {
            $return = (!empty($aMatches[1]) ? $aMatches[1] : $return);
        }

        //if a prefix is set, add that to the return value
        if (!empty($value->prefix)) {
            $return = $value->prefix . $return;
        }

        return $return;
    }

    /**
     * Get site-specific value of variable from record object according to tweet setting object
     * For reddit:mediatype, this will return the type of media, and upload the media to twitter if possible to attach to tweet
     *
     * @param object $record
     * @param object $value
     */
    private function getRssSpecialValue($record, $value): string
    {
        foreach ($this->config->get('tweet_vars') as $var) {
            if ($var->var == $value->subject) {
                $subject = $this->getRssValue($record, $var);
                break;
            }
        }
        if (empty($subject)) {
            $this->logger->write(1, sprintf('getRssSpecialValue failed: subject not found! %s', $value->subject));
            $this->logger->output('getRssSpecialValue failed: subject not found! %s', $value->subject);
            return '';
        }


        $this->attachFile = false;
        $attachFile = false;

        $result = false;
        switch($value->value) {
            case 'special:redditmediatype':
                //determine linked resource type (reddit link, external, image, gallery, etc)

                if (strpos($subject, $record->data->permalink) !== false) {
                    //if post links to itself, text post (no link)
                    $result = 'self';

                } elseif (preg_match('/reddit\.com/i', $subject)) {
                    //link to other subreddit
                    $result = 'crosslink';

                } elseif (preg_match('/\.png|\.gif$|\.jpe?g/i', $subject)) {
                    //naked image
                    $result = 'image';
                    $attachFile = true;

                } elseif (preg_match('/imgur\.com\/.[^\/]/i', $subject) || preg_match('/imgur\.com\/gallery\//i', $subject)) {
                    //single image on imgur.com page
                    $result = 'image';
                    $attachFile = true;

                } elseif (preg_match('/reddituploads\.com/i', $subject)) {
                    //reddit hosted file
                    $result = 'image';
                    $attachFile = true;

                    //ampersands seem to get mangled in posting, messing up the checksum
                    $subject = str_replace('&amp;', '&', $subject);

                } elseif (preg_match('/imgur\.com\/a\//i', $subject)) {
                    //imgur.com album, possibly with multiple images
                    $attachFile = true;

                    //use imgur API here to get number of images in album, set type to [album:n]
                    if (($imageCount = (new Imgur())->getAlbumImageCount($subject)) && is_numeric($imageCount) && $imageCount > 1) {
                        $result = sprintf('album:%d', $imageCount);
                    } else {
                        $result = 'album';
                    }
                } elseif (preg_match('/instagram\.com\/.[^\/]/i', $subject) || preg_match('/instagram\.com\/p\//i', $subject)) {
                    //instagram account link or instagram photo
                    $result = 'instagram';
                    $attachFile = true;

                } elseif (preg_match('/gfycat\.com\//i', $subject)) {
                    //gfycat short video (no sound)
                    $result = 'gif';
                    $attachFile = true;

                } elseif (preg_match('/\.gifv|\.webm|youtube\.com\/|youtu\.be\/|vine\.co\/|vimeo\.com\/|liveleak\.com\//i', $subject)) {
                    //common video hosting websites
                    $result = 'video';

                } elseif (preg_match('/pornhub\.com|xhamster\.com/i', $subject)) {
                    //porn video hosting websites
                    $result = 'video';

                } else {
                    $result = 'link';
                }

                break;
        }

        $this->attachment = [];
        if ($attachFile) {
            $this->attachment = [
                'type' => $result,
                'url' => $subject,
            ];
        }

        return $result;
    }

    /**
     * Get file to attach to tweet, if any
     */
    public function getAttachment(): array
    {
        return $this->attachment ?? [];
    }
}