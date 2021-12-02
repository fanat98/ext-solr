<?php
namespace ApacheSolrForTypo3\Solr\Tests\Integration;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2010-2015 Timo Schmidt <timo.schmidt@dkd.de>
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
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use ApacheSolrForTypo3\Solr\GarbageCollector;
use ApacheSolrForTypo3\Solr\IndexQueue\Indexer;
use ApacheSolrForTypo3\Solr\IndexQueue\RecordMonitor;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;
use ApacheSolrForTypo3\Solr\System\Solr\ResponseAdapter;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * This testcase is used to check if the GarbageCollector can delete garbage from the
 * solr server as expected
 *
 * @author Timo Schmidt
 */
class GarbageCollectorTest extends IntegrationTest
{
    /**
     * @var RecordMonitor
     */
    protected $recordMonitor;

    /**
     * @var DataHandler
     */
    protected $dataHandler;

    /**
     * @var Queue
     */
    protected $indexQueue;

    /**
     * @var GarbageCollector
     */
    protected $garbageCollector;

    /**
     * @var Indexer
     */
    protected $indexer;

    public function setUp(): void
    {
        parent::setUp();
        $this->writeDefaultSolrTestSiteConfiguration();
        $this->recordMonitor = GeneralUtility::makeInstance(RecordMonitor::class);
        $this->dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $this->indexQueue = GeneralUtility::makeInstance(Queue::class);
        $this->garbageCollector = GeneralUtility::makeInstance(GarbageCollector::class);
        $this->indexer = GeneralUtility::makeInstance(Indexer::class);
    }

    /**
     * @todo:
     */
    public function tearDown(): void
    {
        unset($this->recordMonitor);
        unset($this->dataHandler);
        unset($this->indexQueue);
        unset($this->garbageCollector);
        unset($this->indexer);
        parent::tearDown();
    }

    /**
     * @return void
     */
    protected function assertEmptyIndexQueue()
    {
        $this->assertEquals(0, $this->indexQueue->getAllItemsCount(), 'Index queue is not empty as expected');
    }

    /**
     * @return void
     */
    protected function assertNotEmptyIndexQueue()
    {
        $this->assertGreaterThan(0, $this->indexQueue->getAllItemsCount(),
            'Index queue is empty and was expected to be not empty.');
    }

    /**
     * @param $amount
     */
    protected function assertIndexQueryContainsItemAmount($amount)
    {
        $this->assertEquals($amount, $this->indexQueue->getAllItemsCount(),
            'Index queue is empty and was expected to contain ' . (int) $amount . ' items.');
    }

    /**
     * This test case checks the ability to update pages in BE or via TCE, which are outside any site root.
     *
     * @test
     */
    public function catUpdatePageOrSysFolderOutsideOfSiteRoot(): void
    {
        $this->importDataSetFromFixture('can_update_page_or_sys_folder_outside_of_site_root.xml');

        try {
            $this->garbageCollector->processDatamap_afterDatabaseOperations(
                'update',
                'pages',
                444,
                [
                    'title' => 'Renamed: Simple Page outside any site root'
                ],
                $this->dataHandler
            );
            $this->assertTrue(true);
        } catch (Throwable $e) {
            $this->fail('Can not update a simple pages outside of site root. Following is thrown: ' . PHP_EOL . PHP_EOL . $e);
        }

        try {
            $this->garbageCollector->processDatamap_afterDatabaseOperations(
                'update',
                'pages',
                445,
                [
                    'title' => 'Renamed: Simple sys-folder outside any site root'
                ],
                $this->dataHandler
            );
            $this->assertTrue(true);
        } catch (Throwable $e) {
            $this->fail('Can not update a simple sys folder outside of site root. Following is thrown: ' . PHP_EOL . PHP_EOL . $e);
        }
    }

    /**
     * @test
     */
    public function queueItemStaysWhenOverlayIsSetToHidden()
    {
        $this->importDataSetFromFixture('queue_item_stays_when_overlay_set_to_hidden.xml');

        $this->assertIndexQueryContainsItemAmount(1);

        $this->garbageCollector->processDatamap_afterDatabaseOperations('update', 'pages', 2, ['hidden' => 1], $this->dataHandler);
        // index queue not modified
        $this->assertIndexQueryContainsItemAmount(1);
    }

    /**
     * @test
     */
    public function canQueueAPageAndRemoveItWithTheGarbageCollector()
    {
        $this->importDataSetFromFixture('can_queue_a_page_and_remove_it_with_the_garbage_collector.xml');

        // we expect that the index queue is empty before we start
        $this->assertEmptyIndexQueue();

        $dataHandler = $this->dataHandler;
        $this->recordMonitor->processDatamap_afterDatabaseOperations('update', 'pages', 2, [], $dataHandler);

        // we expect that one item is now in the solr server
        $this->assertIndexQueryContainsItemAmount(1);

        $this->garbageCollector->collectGarbage('pages', 2);

        // finally, we expect that the index is empty again
        $this->assertEmptyIndexQueue();
    }

    /**
     * @test
     * @throws \Doctrine\DBAL\Exception
     */
    public function canCollectGarbageFromSubPagesWhenPageIsSetToHiddenAndExtendToSubPagesIsSet()
    {
        $this->importDataSetFromFixture('can_collect_garbage_from_subPages_when_page_is_set_to_hidden_and_extendToSubpages_is_set.xml');

        // we expect that the index queue is empty before we start
        $this->assertEmptyIndexQueue();

        $this->indexQueue->updateItem('pages', 2);
        $this->indexQueue->updateItem('pages', 10);
        $this->indexQueue->updateItem('pages', 100);

        // we expected that three pages are now in the index
        $this->assertIndexQueryContainsItemAmount(3);

        // simulate the database change and build a faked changeset
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->update('pages', ['hidden' => 1], ['uid' => 2]);

        $changeSet = ['hidden' => 1];

        $dataHandler = $this->dataHandler;
        $this->garbageCollector->processDatamap_afterDatabaseOperations('update', 'pages', 2, $changeSet, $dataHandler);

        // finally we expect that the index is empty again because the root page with "extendToSubPages" has been set to
        // hidden = 1
        $this->assertEmptyIndexQueue();
    }

    /**
     * @test
     */
    public function canCollectGarbageFromSubPagesWhenPageIsSetToHiddenAndExtendToSubPagesIsSetForMultipleSubpages()
    {
        $this->importDataSetFromFixture('can_collect_garbage_from_subPages_when_page_is_set_to_hidden_and_extendToSubpages_is_set_multiple_subpages.xml');

        // we expect that the index queue is empty before we start
        $this->assertEmptyIndexQueue();

        $this->indexQueue->updateItem('pages', 2);
        $this->indexQueue->updateItem('pages', 10);
        $this->indexQueue->updateItem('pages', 11);
        $this->indexQueue->updateItem('pages', 12);

        // we expected that three pages are now in the index
        $this->assertIndexQueryContainsItemAmount(4);

        // simulate the database change and build a faked changeset
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->update('pages', ['hidden' => 1], ['uid' => 2]);
        $changeSet = ['hidden' => 1];

        $dataHandler = $this->dataHandler;
        $this->garbageCollector->processDatamap_afterDatabaseOperations('update', 'pages', 2, $changeSet, $dataHandler);

        // finally we expect that the index is empty again because the root page with "extendToSubPages" has been set to
        // hidden = 1
        $this->assertEmptyIndexQueue();
    }

    /**
     * @test
     */
    public function canRemoveDeletedContentElement()
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_remove_content_element.xml');

        $this->indexPageIds([1]);

        // we index a page with two content elements and expect solr contains the content of both
        $this->waitToBeVisibleInSolr();

        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringContainsString('will be removed!', $solrContent, 'solr did not contain rendered page content');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');

        // we delete the second content element
        $beUser = $this->fakeBEUser(1, 0);

        $cmd['tt_content'][88]['delete'] = 1;
        $this->dataHandler->start([], $cmd, $beUser);
        $this->dataHandler->stripslashes_values = 0;
        $this->dataHandler->process_cmdmap();
        $this->dataHandler->process_datamap();

        // after applying the commands solr should be empty (because the page was removed from solr and queued for indexing)
        $this->waitToBeVisibleInSolr();
        $this->assertSolrIsEmpty();

        // we expect the is one item in the indexQueue
        $this->assertIndexQueryContainsItemAmount(1);
        $items = $this->indexQueue->getItems('pages', 1);
        $this->assertSame(1, count($items));

        // we index this item
        $this->indexPageIds([1]);
        $this->waitToBeVisibleInSolr();

        // now the content of the deletec content element should be gone
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringNotContainsString('will be removed!', $solrContent, 'solr did not remove deleted content');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');
    }

    /**
     * @test
     */
    public function canRemoveHiddenContentElement()
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_remove_content_element.xml');

        $this->indexPageIds([1]);

        // we index a page with two content elements and expect solr contains the content of both
        $this->waitToBeVisibleInSolr();

        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringContainsString('will be removed!', $solrContent, 'solr did not contain rendered page content');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');

        // we hide the second content element
        $beUser = $this->fakeBEUser(1, 0);
        $data = [
            'tt_content' => [
                '88' => [
                    'hidden' => 1
                ]
            ]
        ];
        $this->dataHandler->start($data, [], $beUser);
        $this->dataHandler->stripslashes_values = 0;
        $this->dataHandler->process_cmdmap();
        $this->dataHandler->process_datamap();

        // after applying the commands solr should be empty (because the page was removed from solr and queued for indexing)
        $this->waitToBeVisibleInSolr();
        $this->assertSolrIsEmpty();

        // we expect the is one item in the indexQueue
        $this->assertIndexQueryContainsItemAmount(1);
        $items = $this->indexQueue->getItems('pages', 1);
        $this->assertSame(1, count($items));

        // we index this item
        $this->indexPageIds([1]);
        $this->waitToBeVisibleInSolr();

        // now the content of the deletec content element should be gone
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringNotContainsString('will be removed!', $solrContent, 'solr did not remove hidden content');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');
    }

    /**
     * @test
     */
    public function canRemoveContentElementWithEndTimeSetToPast()
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_remove_content_element.xml');

        $this->indexPageIds([1]);

        // we index a page with two content elements and expect solr contains the content of both
        $this->waitToBeVisibleInSolr();

        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringContainsString('will be removed!', $solrContent, 'solr did not contain rendered page content');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');

        // we hide the second content element
        $beUser = $this->fakeBEUser(1, 0);

        $timeStampInPast = time() - (60 * 60 * 24);
        $data = [
            'tt_content' => [
                '88' => [
                    'endtime' => $timeStampInPast
                ]
            ]
        ];
        $this->dataHandler->start($data, [], $beUser);
        $this->dataHandler->stripslashes_values = 0;
        $this->dataHandler->process_cmdmap();
        $this->dataHandler->process_datamap();
        $this->dataHandler->clear_cacheCmd('all');

        // after applying the commands solr should be empty (because the page was removed from solr and queued for indexing)
        $this->waitToBeVisibleInSolr();
        $this->assertSolrIsEmpty();

        // we expect the is one item in the indexQueue
        $this->assertIndexQueryContainsItemAmount(1);
        $items = $this->indexQueue->getItems('pages', 1);
        $this->assertSame(1, count($items));

        // we index this item
        $this->indexPageIds([1]);
        $this->waitToBeVisibleInSolr();

        // now the content of the deletec content element should be gone
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringNotContainsString('will be removed!', $solrContent, 'solr did not remove content hidden by endtime in past');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');
    }

    /**
     * @test
     */
    public function doesNotRemoveUpdatedContentElementWithNotSetEndTime()
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('does_not_remove_updated_content_element_with_not_set_endtime.xml');

        $this->indexPageIds([2]);

        // we index a page with two content elements and expect solr contains the content of both
        $this->waitToBeVisibleInSolr();

        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringContainsString('Will stay after update!', $solrContent, 'solr did not contain rendered page content, which is needed for test.');

        // we hide the second content element
        $beUser = $this->fakeBEUser(1, 0);

        $data = [
            'tt_content' => [
                '88' => [
                    'bodytext' => 'Updated! Will stay after update!'
                ]
            ]
        ];

        $this->dataHandler->start($data, [], $beUser);
        $this->dataHandler->stripslashes_values = 0;
        $this->dataHandler->process_cmdmap();
        $this->dataHandler->process_datamap();
        $this->dataHandler->clear_cacheCmd('all');

        // document should stay in the index, because endtime was not in past but empty
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringContainsString('Will stay after update!', $solrContent, 'solr did not contain rendered page content, which is needed for test.');

        $this->waitToBeVisibleInSolr();

        $this->assertIndexQueryContainsItemAmount(1);
        $items = $this->indexQueue->getItems('pages', 2);
        $this->assertSame(1, count($items));

        // we index this item
        $this->indexPageIds([2]);
        $this->waitToBeVisibleInSolr();

        // now the content of the deletec content element should be gone
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringContainsString('Updated! Will stay after update!', $solrContent, 'solr did not remove content hidden by endtime in past');
    }

    /**
     * @test
     */
    public function canRemoveContentElementWithStartDateSetToFuture()
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_remove_content_element.xml');

        $this->indexPageIds([1]);

        // we index a page with two content elements and expect solr contains the content of both
        $this->waitToBeVisibleInSolr();

        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringContainsString('will be removed!', $solrContent, 'solr did not contain rendered page content');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');

        // we hide the second content element
        $beUser = $this->fakeBEUser(1, 0);

        $timestampInFuture = time() +  (60 * 60 * 24);
        $data = [
            'tt_content' => [
                '88' => [
                    'starttime' => $timestampInFuture
                ]
            ]
        ];
        $this->dataHandler->start($data, [], $beUser);
        $this->dataHandler->stripslashes_values = 0;
        $this->dataHandler->process_cmdmap();
        $this->dataHandler->process_datamap();
        $this->dataHandler->clear_cacheCmd('all');

        // after applying the commands solr should be empty (because the page was removed from solr and queued for indexing)
        $this->waitToBeVisibleInSolr();
        $this->assertSolrIsEmpty();

        // we expect the is one item in the indexQueue
        $this->assertIndexQueryContainsItemAmount(1);
        $items = $this->indexQueue->getItems('pages', 1);
        $this->assertSame(1, count($items));

        // we index this item
        $this->indexPageIds([1]);
        $this->waitToBeVisibleInSolr();

        // now the content of the deletec content element should be gone
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringNotContainsString('will be removed!', $solrContent, 'solr did not remove content hidden by starttime in future');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');
    }


    /**
     * @test
     */
    public function canRemovePageWhenPageIsHidden()
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_remove_page.xml');

        $this->indexPageIds([1,2]);

        // we index two pages and check that both are visible
        $this->waitToBeVisibleInSolr();

        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringContainsString('will be removed!', $solrContent, 'solr did not contain rendered page content');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');
        $this->assertStringContainsString('"numFound":2', $solrContent, 'Expected to have two documents in the index');

        // we hide the seconde page
        $beUser = $this->fakeBEUser(1, 0);

        $data = [
            'pages' => [
                '2' => [
                    'hidden' => 1
                ]
            ]
        ];
        $this->dataHandler->start($data, [], $beUser);
        $this->dataHandler->stripslashes_values = 0;
        $this->dataHandler->process_cmdmap();
        $this->dataHandler->process_datamap();
        $this->dataHandler->clear_cacheCmd('all');

        $this->waitToBeVisibleInSolr();
        $this->assertIndexQueryContainsItemAmount(1);

        // we reindex all queue items
        $siteRepository = GeneralUtility::makeInstance(SiteRepository::class);
        $site = $siteRepository->getFirstAvailableSite();
        $items = $this->indexQueue->getItemsToIndex($site);
        $pages = [];
        foreach($items as $item) {
            $pages[] = $item->getRecordUid();
        }
        $this->indexPageIds($pages);
        $this->waitToBeVisibleInSolr();

        // now only one document should be left with the content of the first content element
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringNotContainsString('will be removed!', $solrContent, 'solr did not remove content from hidden page');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');
        $this->assertStringContainsString('"numFound":1', $solrContent, 'Expected to have two documents in the index');
    }

    /**
     * @test
     */
    public function canRemovePageWhenPageIsDeleted()
    {
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->importDataSetFromFixture('can_remove_page.xml');

        $this->indexPageIds([1,2]);

        // we index two pages and check that both are visible
        $this->waitToBeVisibleInSolr();

        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringContainsString('will be removed!', $solrContent, 'solr did not contain rendered page content');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');
        $this->assertStringContainsString('"numFound":2', $solrContent, 'Expected to have two documents in the index');

        // we hide the seconde page
        $beUser = $this->fakeBEUser(1, 0);

        $cmd['pages'][2]['delete'] = 1;
        $this->dataHandler->start([], $cmd, $beUser);
        $this->dataHandler->stripslashes_values = 0;
        $this->dataHandler->process_cmdmap();
        $this->dataHandler->process_datamap();
        $this->dataHandler->clear_cacheCmd('all');

        $this->waitToBeVisibleInSolr();
        $this->assertIndexQueryContainsItemAmount(1);

        // we reindex all queue items
        $siteRepository = GeneralUtility::makeInstance(SiteRepository::class);
        $site = $siteRepository->getFirstAvailableSite();
        $items = $this->indexQueue->getItemsToIndex($site);
        $pages = [];
        foreach($items as $item) {
            $pages[] = $item->getRecordUid();
        }
        $this->indexPageIds($pages);
        $this->waitToBeVisibleInSolr();

        // now only one document should be left with the content of the first content element
        $solrContent = file_get_contents($this->getSolrConnectionUriAuthority() . '/solr/core_en/select?q=*:*');
        $this->assertStringNotContainsString('will be removed!', $solrContent, 'solr did not remove content from deleted page');
        $this->assertStringContainsString('will stay!', $solrContent, 'solr did not contain rendered page content');
        $this->assertStringContainsString('"numFound":1', $solrContent, 'Expected to have two documents in the index');
    }

    /**
     * @test
     */
    public function canTriggerHookAfterRecordDeletion()
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['postProcessGarbageCollector'][] = TestGarbageCollectorPostProcessor::class;

        $this->importExtTablesDefinition('fake_extension_table.sql');
        $GLOBALS['TCA']['tx_fakeextension_domain_model_foo'] = include($this->getFixturePathByName('fake_extension_tca.php'));
        $this->importDataSetFromFixture('can_delete_custom_record.xml');

        $this->addTypoScriptToTemplateRecord(
            1,
'
            plugin.tx_solr.index.queue {
                foo = 1
                foo {
                    table = tx_fakeextension_domain_model_foo
                    fields.title = title
                }
            }
        ');
        $this->cleanUpSolrServerAndAssertEmpty();
        $this->fakeLanguageService();

        // we hide the seconde page
        $beUser = $this->fakeBEUser(1, 0);

        $this->addToQueueAndIndexRecord('tx_fakeextension_domain_model_foo', 111);
        $this->waitToBeVisibleInSolr();
        $this->assertSolrContainsDocumentCount(1);

        $cmd['tx_fakeextension_domain_model_foo'][111]['delete'] = 1;
        $this->dataHandler->start([], $cmd, $beUser);
        $this->dataHandler->stripslashes_values = 0;
        $this->dataHandler->process_cmdmap();
        $this->dataHandler->process_datamap();
        $this->dataHandler->clear_cacheCmd('all');

        $this->waitToBeVisibleInSolr();
        $this->assertSolrIsEmpty();

            // since our hook is a singleton we check here if it was called.
            /** @var TestGarbageCollectorPostProcessor $hook */
        $hook = GeneralUtility::makeInstance(TestGarbageCollectorPostProcessor::class);
        $this->assertTrue($hook->isHookWasCalled());

            // reset the hooks
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['postProcessGarbageCollector'] = [];
    }

    /**
     * @param string $table
     * @param int $uid
     * @return ResponseAdapter
     */
    protected function addToQueueAndIndexRecord($table, $uid)
    {
        // write an index queue item
        $this->indexQueue->updateItem($table, $uid);

        // run the indexer
        $items = $this->indexQueue->getItems($table, $uid);
        foreach ($items as $item) {
            $result = $this->indexer->index($item);
        }

        return $result;
    }

    /**
     *
     */
    protected function fakeLanguageService()
    {
        /** @var $languageService  \TYPO3\CMS\Core\Localization\LanguageService */
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $GLOBALS['LANG'] = $languageService;
    }
}
