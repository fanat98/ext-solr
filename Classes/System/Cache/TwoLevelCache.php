<?php
namespace ApacheSolrForTypo3\Solr\System\Cache;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2016 Timo Schmidt <timo.schmidt@dkd.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Provides a two level cache that uses an in memory cache as the first level cache and
 * the TYPO3 caching framework cache as the second level cache.
 *
 * @author Timo Schmidt <timo.schmidt@dkd.de>
 */
class TwoLevelCache
{
    /**
     * @var string
     */
    protected $cacheName = '';

    /**
     * @var array
     */
    protected static $firstLevelCache = [];

    /**
     * @var FrontendInterface
     */
    protected $secondLevelCache = null;

    /**
     * @param string $cacheName
     * @param FrontendInterface $secondaryCacheFrontend
     */
    public function __construct(string $cacheName, FrontendInterface $secondaryCacheFrontend = null)
    {
        $this->cacheName = $cacheName;
        if ($secondaryCacheFrontend == null) {
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            $this->secondLevelCache = $cacheManager->getCache($cacheName);
        } else {
            $this->secondLevelCache = $secondaryCacheFrontend;
        }
    }

    /**
     * Retrieves a value from the first level cache.
     *
     * @param string $cacheId
     * @return mixed|null
     */
    protected function getFromFirstLevelCache(string $cacheId)
    {
        if (!empty(self::$firstLevelCache[$this->cacheName][$cacheId])) {
            return self::$firstLevelCache[$this->cacheName][$cacheId];
        }

        return null;
    }

    /**
     * Write a value to the first level cache.
     *
     * @param string $cacheId
     * @param mixed $value
     */
    protected function setToFirstLevelCache(string $cacheId, $value): void
    {
        self::$firstLevelCache[$this->cacheName][$cacheId] = $value;
    }

    /**
     * Retrieves a value from the first level cache if present and
     * from the second level if not.
     *
     * @param string $cacheId
     * @return mixed
     */
    public function get(string $cacheId)
    {
        $cacheId = $this->sanitizeCacheId($cacheId);

        $firstLevelResult = $this->getFromFirstLevelCache($cacheId);
        if ($firstLevelResult !== null) {
            return $firstLevelResult;
        }

        $secondLevelResult = $this->secondLevelCache->get($cacheId);
        $this->setToFirstLevelCache($cacheId, $secondLevelResult);

        return $secondLevelResult;
    }

    /**
     * Write a value to the first and second level cache.
     *
     * @param string $cacheId
     * @param mixed $value
     */
    public function set(string $cacheId, $value): void
    {
        $cacheId = $this->sanitizeCacheId($cacheId);

        $this->setToFirstLevelCache($cacheId, $value);
        $this->secondLevelCache->set($cacheId, $value);
    }

    /**
     * Flushes the cache
     */
    public function flush(): void
    {
        self::$firstLevelCache[$this->cacheName] = [];
        $this->secondLevelCache->flush();
    }

    /**
     * Sanitizes the cache id to ensure compatibility with the FrontendInterface::PATTERN_ENTRYIDENTIFIER
     *
     * @see \TYPO3\CMS\Core\Cache\Frontend\AbstractFrontend::isValidEntryIdentifier()
     *
     * @param string $cacheEntryIdentifier
     * @return string
     */
    protected function sanitizeCacheId(string $cacheId): string
    {
        return preg_replace('/[^a-zA-Z0-9_%\\-&]/', '-', $cacheId);
    }
}
