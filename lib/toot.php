<?php
declare(strict_types=1);

namespace Mastobot\Lib;

use Mastobot\Lib\Base;

/**
 * Toot class, post toot from whatever source, optionally adding media
 */
class Toot extends Base
{
    private $mediaIds = [];

    /**
     * Post toots to Mastodon , add media if present (set by setMedia())
     */
    public function post(array $toots = []): bool
    {
        if (!$toots && !$this->mediaIds) {
            $this->logger->write(3, 'Nothing to post.');
            $this->logger->output('Nothing to post.');

            return false;
        }

        foreach ($toots as $toot) {
            if (!empty($this->mediaIds)) {
                $sMediaIds = implode(',', $this->mediaIds);
                $this->logger->output('Posting: [%dch] %s (with attachment)', strlen($toot), utf8_decode($toot));
                $oRet = $this->mastodon->postStatus($toot);//'media_ids' => $sMediaIds]
            } else {
                $this->logger->output('Posting: [%dch] %s', strlen($toot), utf8_decode($toot));
                $oRet = $this->mastodon->postStatus($toot);
            }

            if (isset($oRet->errors)) {
                $this->logger->write(2, sprintf(
                    'API call failed: statuses/update (%s)',
                    $oRet->errors[0]->message),
                    ['toot' => $toot]
                );
                $this->logger->output('- Error: %s (code %s)', $oRet->errors[0]->message, $oRet->errors[0]->code);

                return false;
            }
        }

        return true;
    }

    /**
     * Set media ids to be posted with next toot (max 4?)
     */
    public function setMedia(array $aMediaIds): self
    {
        $this->mediaIds = $aMediaIds;

        if (count($this->mediaIds) > 4) {
            $this->mediaIds = array_slice($aMediaIds, 0, 4);
        }

        return $this;
    }
}