<?php
declare(strict_types=1);

/**
 * TODO:
 * v stop base64 encoding attachments to shrink size (use raw binary)
 * - implement clearing a variable from tweet because it's been attached
 *   i.e. when attaching go.com/image.jpg, don't include url in tweet
 *   but do include when attaching imgur gallery or instagram account
 * - always same filesize is written to disk (var only set once?)
 */
namespace Mastobot\Lib;

use DOMDocument;
use DOMXPath;
use Mastobot\Lib\Base;
use Mastobot\Custom\Imgur;

/**
 * Media class - upload media to mastodon, if possible
 */
class Media extends Base
{
    /**
     * Upload file path to mastodon to attach to tweet if possible, return media id
     *
     * @return string|false
     */
    public function upload(string $filepath)
    {
        $this->logger->output(sprintf('Reading file %s..', $filepath));

        if (strpos($filepath, 'http') === 0) {
            //url, so download and store temp file
            $this->logger->output('- is URL, downloading..');
            $fileBinary = file_get_contents($filepath);
            if ($fileBinary) {
                if (strlen($fileBinary) > 5 * 1024 * 1024) {
                    $this->logger->write(2, sprintf('Image is too large for tweet: %s (%d bytes)', $filepath, strlen($fileBinary)));
                    $this->logger->output('- Error: file is too large! %d bytes, max is 5MB', strlen($fileBinary));
                    return false;
                } else {
                    $filepath = getcwd() . '/tempimg.jpg';
                    file_put_contents($filepath, $fileBinary);
                    $this->logger->output('- wrote %s bytes to disk', number_format(strlen($fileBinary)));
                }
            }
        }

        $return = $this->mastodon->upload('media/upload', ['media' => $filepath]);
        if (isset($return->errors)) {
            $this->logger->write(2, sprintf('API call failed: media/upload (%s)', $return->errors[0]->message), ['file' => $filepath]);
            $this->logger->output('- Error: ' . $return->errors[0]->message . ' (code ' . $return->errors[0]->code . ')');

            return false;

        } elseif (isset($return->error)) {
            $this->logger->write(2, sprintf('API call failed: media/upload(%s)', $return->error), ['file' => $filepath]);
            $this->logger->output(sprintf('- Error: %s', $return->error));
        } else {
            $this->logger->output('- Uploaded %s to attach to next tweet', $filepath);

            return $return->media_id_string;
        }

        return false;
    }

    /**
     * Wrapper to upload media to mastodon according to URL and type, return media type
     *
     * @return string|false
     */
    public function uploadFromUrl(string $url, string $type)
    {
        //albums can have a numeral if they have multiple pics, strip that out for here
        if (preg_match('/album:\d/', $type)) {
            $type = 'album';
        }

        switch ($type) {
            default:
            case 'image':
                return $this->upload($url);

            case 'gallery':
            case 'album':
                return $this->uploadFromGallery($url);

            case 'instagram':
                return $this->uploadFromInstagram($url);

            case 'gif':
                return $this->uploadVideoFromGfycat($url);
        }
    }

    /**
     * Upload media to mastodon from imgur gallery page, return media ids if possible
     */
    private function uploadFromGallery(string $url): array
    {
        //use the API
        $imageUrls = (new Imgur())->getFourAlbumImages($url);

        //if we have at least one image, upload it to attach to tweet
        $mediaIds = [];
        if ($imageUrls) {
            foreach ($imageUrls as $imageUrl) {
                $mediaIds[] = $this->upload($imageUrl);
            }
        }

        return array_filter($mediaIds);
    }

    /**
     * Upload media to mastodon from imgur page, return media id if possible
     *
     * @return string|false
     */
    private function uploadFromPage(string $sUrl)
    {
        //imgur implements meta tags that indicate to twitter which urls to use for inline preview
        //so we're going to use those same meta tags to determine which urls to upload
        //format: <meta name="twitter:image:src" content="http://i.imgur.com/[a-zA-Z0-9].ext"/>

        //fetch image from twitter meta tag
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadHTML(file_get_contents($sUrl));

        $image = '';
        $xpath = new DOMXpath($dom);
        $metaTags = $xpath->query('//meta[@name="twitter:image:src"]');
        foreach ($metaTags as $tag) {
            $image = $tag->getAttribute('content');
            break;
        }

        //march 2016: imgur changed their meta tags
        if (empty($image)) {
            $metaTags = $xpath->query('//meta[@name="twitter:image"]');
            foreach ($metaTags as $tag) {
                $image = $tag->getAttribute('content');
                break;
            }
        }

        if (!empty($image)) {
            //we want the page url truncated from the tweet, so use it as the index name
            return $this->upload($image, $sUrl);
        }

        return false;
    }

    /**
     * Upload media to mastodon from instagram, return media id if possible
     *
     * @return string|false
     */
    private function uploadFromInstagram(string $url)
    {
        //instagram implements og:image meta tag listing exact url of image
        //this works on both account pages (tag contains user avatar) and photo pages (tag contains photo url)

        //we want instagram photo urls to be truncated from the tweet, but not instagram account urls
        if (preg_match('/instagram\.com\/p\//i', $url)) {
            //custom name equal to original url
            $name = $url;
        } else {
            //use url as index name
            $name = false;
        }

        //fetch image from twitter meta tag
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadHTML(file_get_contents($url));

        $xpath = new DOMXpath($dom);
        $metaTags = $xpath->query('//meta[@property="og:image"]');
        foreach ($metaTags as $tag) {
            $image = $tag->getAttribute('content');
            break;
        }

        if (!empty($image)) {
            return $this->upload($image, $name);
        }

        return false;
    }

    /**
     * Upload media to mastodon from gfycat, return media id if possible
     *
     * @param string $url
     *
     * @return string|false
     */
    public function uploadVideoFromGfycat(string $url)
    {
        //construct json info url
        $jsonUrl = str_replace('gfycat.com/', 'api.gfycat.com/v1/gfycats/', $url);
        if ($jsonUrl == $url) {
            return false;
        }

        $gfycatInfo = @json_decode(file_get_contents($jsonUrl));
        if ($gfycatInfo && !empty($gfycatInfo->gfyItem->mp4Url)) {
            return $this->uploadVideoToTwitter($gfycatInfo->gfyItem->mp4Url, 'video/mp4');
        }

        return false;
    }

    /**
     * Upload video to mastodon from URL, return media id if possible
     *
     * @return string|false
     */
    private function uploadVideoToTwitter(string $filepath, string $type)
    {
        $this->logger->output(sprintf('Reading file %s..', $filepath));

        //need to download and save the file since the library expects a local file
        $videoBinary = file_get_contents($filepath);
        if (strlen($videoBinary) < 15 * pow(1024, 2)) {

            $this->logger->output('- Saving %s bytes to disk..', number_format(strlen($videoBinary)));
            $tempFilepath = getcwd() . '/video.mp4';
            file_put_contents($tempFilepath, $videoBinary);

            $this->logger->output('- Uploading to mastodon(chunked)..');
            $return = $this->mastodon->upload('media/upload', ['media' => $tempFilepath, 'media_type' => $type], true);
            if (isset($return->errors)) {
                $this->logger->write(2, sprintf('API call failed: media/upload (%s, chunked)', $return->errors[0]->message), ['file' => $filepath]
                );
                $this->logger->output('- Error: ' . $return->errors[0]->message . ' (code ' . $return->errors[0]->code . ')');

                return false;

            } elseif (isset($return->error)) {
                $this->logger->write(2, sprintf('API call failed: media/upload (%s, chunked)', $return->error), ['file' => $filepath]
                );
                $this->logger->output(sprintf('- Error: %s', $return->error));
            } else {
                $this->logger->output('- Uploaded video %s to attach to next tweet', $filepath);

                return $return->media_id_string;
            }
        } else {
            $this->logger->write(2, sprintf('File is too large! File is %d bytes, max is 15MB (%s)', strlen($videoBinary), $filepath));
            $this->logger->output('- File is too large! %d bytes, max is 15MB', strlen($videoBinary));
        }

        return false;
    }
}