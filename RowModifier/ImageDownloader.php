<?php
/**
 * Copyright (c) 2016 H&O E-commerce specialisten B.V. (http://www.h-o.nl/)
 * See LICENSE.txt for license details.
 */

namespace Ho\Import\RowModifier;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Promise;
use Magento\Framework\App\Filesystem\DirectoryList;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Class AsyncImageDownloader
 *
 * @todo Implement caching strategory. We don't need to download the file each time, but would be nice if it gets
 *       downloaded once in a while.
 * @todo Implement additional images download
 * @package Ho\Import
 */
class ImageDownloader extends AbstractRowModifier
{

    /**
     * Console progressbar component.
     *
     * @var ProgressBar
     */
    protected $progressBar;

    /**
     * Gruzzle Http Client
     * @var HttpClient
     */
    private $httpClient;

    /**
     * Get Magento directories to place images.
     *
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * The concurrent target to download
     *
     * @var int
     */
    protected $concurrent = 25;

    /**
     * Array to cache all requests so they don't get downloaded twice
     *
     * @var \[]
     */
    protected $cachedRequests = [];

    /**
     * Use existing files or redownload alle files
     *
     * @var bool
     */
    protected $useExisting = false;

    private $stopwatch;

    /**
     * AsyncImageDownloader constructor.
     *
     * @param DirectoryList $directoryList
     * @param ConsoleOutput $consoleOutput
     */
    public function __construct(
        DirectoryList $directoryList,
        ConsoleOutput $consoleOutput
    ) {
        parent::__construct($consoleOutput);
        $this->directoryList = $directoryList;
        $this->httpClient    = new HttpClient();
        $this->progressBar   = new \Symfony\Component\Console\Helper\ProgressBar($this->consoleOutput);
        $this->stopwatch   = new \Symfony\Component\Stopwatch\Stopwatch();
    }

    /**
     * Actually download the images async
     *
     * @todo Implement the additional fields, those are comma seperated
     * @return void
     */
    public function process()
    {
        $imageFields = ['swatch_image','image', 'small_image', 'thumbnail'];
        $imageArrayFields = ['additional_images'];

        $itemCount = count($this->items);
        $this->consoleOutput->writeln("<info>Downloading images for {$itemCount} items</info>");
        $this->progressBar->start();

        if (! file_exists($this->directoryList->getPath('media') . '/import/')) {
            mkdir($this->directoryList->getPath('media') . '/import/', 0777, true);
        }


        $requestGenerator = function () use ($imageFields, $imageArrayFields) {
            foreach ($this->items as &$item) {
                foreach ($imageFields as $field) {
                    if (!isset($item[$field])) {
                        continue;
                    }

                    if ($promise = $this->downloadAsync($item[$field], $item)) {
                        yield $promise;
                    }
                }
                foreach ($imageArrayFields as $imageArrayField) {
                    if (! isset($item[$imageArrayField])) {
                        continue;
                    }

                    $item[$imageArrayField] = array_unique(explode(',', $item[$imageArrayField]));
                    foreach ($item[$imageArrayField] as &$value) {
                        if ($promise = $this->downloadAsync($value, $item)) {
                            yield $promise;
                        }
                    }
                }
            }
        };

        $pool = new \GuzzleHttp\Pool($this->httpClient, $requestGenerator(), [
            'concurrency' => $this->getConcurrent(),
        ]);
        $pool->promise()->wait();

        $this->progressBar->finish();
        $this->consoleOutput->write("\n");

        //Implode all image array fields
        foreach ($this->items as &$item) {
            foreach ($imageArrayFields as $imageArrayField) {
                if (! isset($item[$imageArrayField])) {
                    continue;
                }

                $item[$imageArrayField] = implode(',', $item[$imageArrayField]);
            }
        }
    }

    /**
     * Download the actual image async and resolve the to the new value
     *
     * @param string &$value
     * @param array $item
     *
     * @return \Closure|Promise\PromiseInterface
     */
    protected function downloadAsync(&$value, &$item)
    {
        return function () use (&$value, &$item) {
            $fileName   = str_replace(' ', '', basename($value));
            $targetPath = $this->directoryList->getPath('media') . '/import/' . $fileName;

            if (isset($this->cachedRequests[$fileName])) {
                $promise = $this->cachedRequests[$fileName];
            } elseif (file_exists($targetPath)) { //@todo honor isUseExisting
                $value = $fileName;
                return null;
            } else {
                $this->progressBar->advance();
                $promise = $this->httpClient
                    ->getAsync($value, [
                        'sink' => $targetPath,
                        'connect_timeout' => 5
                    ]);
            }

            $promise
                ->then(function (\GuzzleHttp\Psr7\Response $response) use (
                    &$value,
                    $fileName
                ) {
                    $response->getBody()->close();
                    $value = $fileName;
                })
                ->otherwise(function (\GuzzleHttp\Psr7\Response $response) use (
                    &$value,
                    &$item,
                    $fileName,
                    $targetPath
                ) {
                    $response->getBody()->close();
                    unlink($targetPath); // clean up any remaining file pointers if the download failed

                    $this->consoleOutput->writeln(
                        "\n<comment>Image can not be downloaded: {$fileName}}</comment>"
                    );

                    foreach ($item as &$itemValue) {
                        if ($value == $itemValue) {
                            if (is_array($itemValue)) {
                                foreach ($itemValue as &$itemArrValue) {
                                    if ($value == $itemArrValue) {
                                        $itemArrValue = null;
                                    }
                                }
                            }
                            $itemValue = null;
                        }
                    }
                    $value = null;
                });

            return $this->cachedRequests[$fileName] = $promise;
        };
    }

    /**
     * Get the amount of concurrent images downloaded
     *
     * @return int
     */
    public function getConcurrent()
    {
        return $this->concurrent;
    }

    /**
     * Set the amount of concurrent images downloaded
     *
     * @param int $concurrent
     */
    public function setConcurrent(int $concurrent)
    {
        $this->concurrent = $concurrent;
    }

    /**
     * Overwrite existing images or not.
     *
     * @return boolean
     */
    public function isUseExisting()
    {
        return $this->useExisting;
    }

    /**
     * Overwrite existing images or not.
     * @param boolean $useExisting
     * @return void
     */
    public function setUseExisting($useExisting)
    {
        $this->useExisting = (bool) $useExisting;
    }
}
