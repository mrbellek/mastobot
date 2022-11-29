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
    private $fileList = [];

    private const DS = DIRECTORY_SEPARATOR;

    /**
     * Rebuild the index of files (and folders) in the root folder, if needed
     */
    public function rebuildIndex(): bool
    {
        //do not recreate index if filelist exists and is younger than the max age
        if ($this->config->get('filelist') && (strtotime($this->config->get('filelist_mtime')) + $this->config->get('max_index_age') > time())) {

            $this->logger->output('- Using cached filelist');
            $this->fileList = (array) $this->config->get('filelist');

            return true;
        }

        $folder = DOCROOT . $this->config->get('folder');
        $this->logger->output('- Scanning %s', $folder);

        $files = $this->recursiveScan($folder);
        if ($files) {
            natcasesort($files);

            //convert list into keys of array with postcount
            $this->fileList = [];
            foreach ($files as $sFile) {
                $this->fileList[utf8_encode($sFile)] = 0;
            }
            unset($this->fileList['.']);
            unset($this->fileList['..']);

            if ($oldFileList = $this->config->get('filelist')) {
                foreach ($oldFileList as $file => $postCount) {

                    //carry over postcount from existing files
                    if (isset($this->fileList[$file])) {
                        $this->fileList[$file] = $postCount;
                    }
                }
            }

            $this->logger->output('- Writing filelist with %d entries to cache', count($this->fileList));
            $this->config->set('filelist_mtime', date('Y-m-d H:i:s'));
            $this->writeFileList();

            return true;
        }

        return false;
    }

    /**
     * Recursively scan folder contents
     */
    private function recursiveScan(string $folder): array
    {
        if (!is_dir($folder)) {
            return [];
        }

		$files = scandir($folder);

		foreach ($files as $key => $file) {

			if (is_dir($folder . self::DS . $file) && !in_array($file, ['.', '..'])) {
				unset($files[$key]);
				$subFiles = $this->recursiveScan($folder . self::DS . $file);
				foreach ($subFiles as $subFile) {
					if (!in_array($subFile, ['.', '..'])) {
						$files[] = $file . self::DS . $subFile;
					}
				}
			}
		}

		return $files;
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
            $filename = $this->getRandomUnposted($folder);
        } else {
            $filename = $this->getRandom($folder);
        }

        if (!$filename) {
            return false;
        }

        //get file info
        $filePath = DOCROOT . $this->config->get('folder') . self::DS . utf8_decode($filename);
        $imageInfo = getimagesize($filePath);

        //construct array
		$fileInfo = [
			'filepath'  => $filePath,
			'dirname'   => pathinfo($filename, PATHINFO_DIRNAME),
			'filename'  => $filename,
			'basename'  => pathinfo($filePath, PATHINFO_FILENAME),
			'extension' => pathinfo($filePath, PATHINFO_EXTENSION),
			'size'      => number_format(filesize($filePath) / 1024, 0) . 'k',
			'width'     => $imageInfo[0],
			'height'    => $imageInfo[1],
			'created'   => date('Y-m-d', filectime($filePath)),
			'modified'  => date('Y-m-d', filemtime($filePath)),
        ];

        //increase postcount for this file by 1 and write filelist to disk
        $this->increment($fileInfo);

        $this->logger->output('- File: %s', $filePath);

		return $fileInfo;
    }

    /**
     * Get random file with lowest postcount from index
     */
    private function getRandom(string $folder = null): string
    {
        $this->logger->output('- Getting random file');

        //get lowest postcount in index, optionally in a specific folder
        $lowestCount = false;
        foreach ($this->fileList as $filename => $count) {
            if (!$folder || strpos($filename, $folder) === 0) {
                if ($lowestCount === false || $count < $lowestCount) {
                    $lowestCount = $count;
                }
            }
        }

        //create temp array of files with lowest postcount
        $tempIndex = array_filter((array) $this->fileList, function($i) use($lowestCount) {
            return $i == $lowestCount;
        });

        //optionally filter only on specific folder
        if ($folder) {
            $tempIndex = array_filter($tempIndex, function($filename) use ($folder) {
                return strpos($filename, $folder) === 0;
            }, ARRAY_FILTER_USE_KEY);
        }

        //array empty? don't return
        if (!$tempIndex) {
            return '';
        }

        //pick random file
        return array_rand($tempIndex);
    }

    /**
     * Get random unposted file
     */
    private function getRandomUnposted(string $folder = null): string
    {
        $this->logger->output('- Getting unposted random file');

        //create temp array of all files that have postcount = 0
        $tempIndex = array_filter($this->fileList, function($i) {
            return $i == 0;
        });

        //optionally filter only on specific folder
        if ($folder) {
            $tempIndex = array_filter($tempIndex, function($filename) use ($folder) {
                return strpos($filename, $folder) === 0;
            }, ARRAY_FILTER_USE_KEY);
        }

        //pick random file
        return array_rand($tempIndex);
    }

    /**
     * Increase postcount of given file, write index to disk
     */
    public function increment(array $fileInfo): void
    {
        $this->fileList[$fileInfo['filename']]++;

        $this->writeFileList();
    }

    /**
     * Write file index to disk, save timestamp
     */
    private function writeFileList(): void
    {
        $this->config->set('filelist', $this->fileList);
        $this->config->writeConfig();
    }
}