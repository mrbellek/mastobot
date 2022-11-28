<?php
declare(strict_types=1);

namespace Mastobot\Lib;

use Mastobot\Lib\Base;

/**
 * File class, create and maintain file listing of files in a folder
 *
 * @param config:folder
 * @param config:max_index_age
 * @param config:post_only_once
 */
class File extends Base
{
    private $aFileList;

    private const DS = DIRECTORY_SEPARATOR;

    /**
     * Rebuild the index of files (and folders) in the root folder, if needed
     */
    public function rebuildIndex(): bool
    {
        //do not recreate index if filelist exists and is younger than the max age
        if ($this->config->get('filelist') && (strtotime($this->config->get('filelist_mtime')) + $this->config->get('max_index_age') > time())) {

            $this->logger->output('- Using cached filelist');
            $this->aFileList = (array) $this->config->get('filelist');

            return true;
        }

        $sFolder = DOCROOT . $this->config->get('folder');
        $this->logger->output('- Scanning %s', $sFolder);

        $aFileList = $this->recursiveScan($sFolder);
        if ($aFileList) {
            natcasesort($aFileList);

            //convert list into keys of array with postcount
            $this->aFileList = [];
            foreach ($aFileList as $sFile) {
                $this->aFileList[utf8_encode($sFile)] = 0;
            }
            unset($this->aFileList['.']);
            unset($this->aFileList['..']);

            if ($aOldFileList = $this->config->get('filelist')) {
                foreach ($aOldFileList as $sFile => $iPostCount) {

                    //carry over postcount from existing files
                    if (isset($this->aFileList[$sFile])) {
                        $this->aFileList[$sFile] = $iPostCount;
                    }
                }
            }

            $this->logger->output('- Writing filelist with %d entries to cache', count($this->aFileList));
            $this->config->set('filelist_mtime', date('Y-m-d H:i:s'));
            $this->writeFileList();

            return true;
        }

        return false;
    }

    /**
     * Recursively scan folder contents
     */
    private function recursiveScan(string $sFolder): array
    {
        if (!is_dir($sFolder)) {
            return [];
        }

		$aFiles = scandir($sFolder);

		foreach ($aFiles as $key => $sFile) {

			if (is_dir($sFolder . self::DS . $sFile) && !in_array($sFile, ['.', '..'])) {
				unset($aFiles[$key]);
				$aSubFiles = $this->recursiveScan($sFolder . self::DS . $sFile);
				foreach ($aSubFiles as $sSubFile) {
					if (!in_array($sSubFile, ['.', '..'])) {
						$aFiles[] = $sFile . self::DS . $sSubFile;
					}
				}
			}
		}

		return $aFiles;
    }

    /**
     * @return array|false
     */
    public function getFromFolder(string $folder)
    {
        return $this->get($folder);
    }

    /**
     * Get random file from the index, with all info
     *
     * @return array|false
     */
    public function get(string $folder = null)
    {
        $this->logger->output('Getting file..');

        //rebuild index, if needed
        $this->rebuildIndex();

        //get random file (lowest postcount) or random unposted file
        if ($this->config->get('post_only_once', false)) {
            $sFilename = $this->getRandomUnposted($folder);
        } else {
            $sFilename = $this->getRandom($folder);
        }

        if (!$sFilename) {
            return false;
        }

        //get file info
        $sFilePath = DOCROOT . $this->config->get('folder') . self::DS . utf8_decode($sFilename);
        $aImageInfo = getimagesize($sFilePath);

        //construct array
		$aFile = [
			'filepath'  => $sFilePath,
			'dirname'   => pathinfo($sFilename, PATHINFO_DIRNAME),
			'filename'  => $sFilename,
			'basename'  => pathinfo($sFilePath, PATHINFO_FILENAME),
			'extension' => pathinfo($sFilePath, PATHINFO_EXTENSION),
			'size'      => number_format(filesize($sFilePath) / 1024, 0) . 'k',
			'width'     => $aImageInfo[0],
			'height'    => $aImageInfo[1],
			'created'   => date('Y-m-d', filectime($sFilePath)),
			'modified'  => date('Y-m-d', filemtime($sFilePath)),
        ];

        //increase postcount for this file by 1 and write filelist to disk
        $this->increment($aFile);

        $this->logger->output('- File: %s', $sFilePath);

		return $aFile;
    }

    /**
     * Get random file with lowest postcount from index
     */
    private function getRandom(string $folder = null): string
    {
        $this->logger->output('- Getting random file');

        //get lowest postcount in index, optionally in a specific folder
        $iLowestCount = false;
        foreach ($this->aFileList as $sFilename => $iCount) {
            if (!$folder || strpos($sFilename, $folder) === 0) {
                if ($iLowestCount === false || $iCount < $iLowestCount) {
                    $iLowestCount = $iCount;
                }
            }
        }

        //create temp array of files with lowest postcount
        $aTempIndex = array_filter((array) $this->aFileList, function($i) use($iLowestCount) {
            return $i == $iLowestCount;
        });

        //optionally filter only on specific folder
        if ($folder) {
            $aTempIndex = array_filter($aTempIndex, function($filename) use ($folder) {
                return strpos($filename, $folder) === 0;
            }, ARRAY_FILTER_USE_KEY);
        }

        //array empty? don't return
        if (!$aTempIndex) {
            return '';
        }

        //pick random file
        return array_rand($aTempIndex);
    }

    /**
     * Get random unposted file
     */
    private function getRandomUnposted(string $folder = null): string
    {
        $this->logger->output('- Getting unposted random file');

        //create temp array of all files that have postcount = 0
        $aTempIndex = array_filter($this->aFileList, function($i) {
            return $i == 0;
        });

        //optionally filter only on specific folder
        if ($folder) {
            $aTempIndex = array_filter($aTempIndex, function($filename) use ($folder) {
                return strpos($filename, $folder) === 0;
            }, ARRAY_FILTER_USE_KEY);
        }

        //pick random file
        return array_rand($aTempIndex);
    }

    /**
     * Increase postcount of given file, write index to disk
     */
    public function increment(array $aFile): void
    {
        $this->aFileList[$aFile['filename']]++;

        $this->writeFileList();
    }

    /**
     * Write file index to disk, save timestamp
     */
    private function writeFileList(): void
    {
        $this->config->set('filelist', $this->aFileList);
        $this->config->writeConfig();
    }
}