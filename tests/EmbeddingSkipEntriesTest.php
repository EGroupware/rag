<?php
/**
 * EGroupware RAG: Test that entries which can not be indexed do not block all entries behind them
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use PHPUnit\Framework\Attributes\DataProvider;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * Calendar plugin restricted to our test events, in chunks of just 3 entries
 */
class EmbeddingSkipEntriesCalendar extends Embedding\Calendar
{
	const CHUNK_SIZE = 3;
	const RAG_EXTRA_CONDITION = 'cal_id BETWEEN 999950 AND 999969';
}

/**
 * Infolog plugin restricted to our test entries, in chunks of just 3 entries
 */
class EmbeddingSkipEntriesInfolog extends Embedding\Infolog
{
	const CHUNK_SIZE = 3;
	const RAG_EXTRA_CONDITION = 'info_id BETWEEN 999950 AND 999969';
}

/**
 * Embedding\Base::getUpdated() returns the first CHUNK_SIZE entries not yet in the index and asks again as long as it got
 * a full chunk. Entries which are not stored in the index, because there is nothing to embed (empty title and description)
 * or because embedding them failed, were returned again and again, blocking all entries behind them: a calendar
 * with 10 old events with empty title queried the DB endlessly and never indexed its other 7,000 events.
 *
 * The problem is not specific to an app, it is tested with two (calendar and infolog).
 */
class EmbeddingSkipEntriesTest extends Api\LoggedInTest
{
	const FIRST_ID = 999950;
	const ENTRIES = 7;	// more than 2 chunks of 3

	public static function plugins() : array
	{
		return [
			'calendar' => [EmbeddingSkipEntriesCalendar::class],
			'infolog' => [EmbeddingSkipEntriesInfolog::class],
		];
	}

	protected function setUp() : void
	{
		parent::setUp();
		$this->removeEntries();
		$db = $GLOBALS['egw']->db;
		$account_id = $GLOBALS['egw_info']['user']['account_id'];
		for ($id = self::FIRST_ID; $id < self::FIRST_ID + self::ENTRIES; ++$id)
		{
			$db->insert('egw_cal', [
				'cal_uid' => 'rag-skip-test-'.$id,
				'cal_owner' => $account_id,
				'cal_creator' => $account_id,
				'cal_title' => '',
				'cal_description' => null,
				'cal_created' => time(),
				'cal_modified' => time(),
				'range_start' => time(),
				'range_end' => time() + 3600,
			], ['cal_id' => $id], __LINE__, __FILE__, 'calendar');
			$db->insert('egw_infolog', [
				'info_type' => 'task',
				'info_owner' => $account_id,
				'info_subject' => '',
				'info_des' => null,
				'info_status' => 'open',
				'info_datemodified' => time(),
				'info_modifier' => $account_id,
			], ['info_id' => $id], __LINE__, __FILE__, 'infolog');
		}
	}

	protected function tearDown() : void
	{
		$this->removeEntries();
		parent::tearDown();
	}

	private function removeEntries() : void
	{
		$db = $GLOBALS['egw']->db;
		$db->delete('egw_cal', ['cal_id >= '.self::FIRST_ID, 'cal_id < '.(self::FIRST_ID + self::ENTRIES)], __LINE__, __FILE__, 'calendar');
		$db->delete('egw_infolog', ['info_id >= '.self::FIRST_ID, 'info_id < '.(self::FIRST_ID + self::ENTRIES)], __LINE__, __FILE__, 'infolog');
	}

	/**
	 * @param class-string<Embedding\Base> $class
	 */
	#[DataProvider('plugins')]
	public function testEntriesNotStoredInTheIndexBlockTheOnesBehindThem(string $class) : void
	{
		$plugin = new $class();
		$seen = [];
		foreach ($plugin->getUpdated() as $row)
		{
			$seen[] = (int)$row[$class::ID];
			if (count($seen) >= 4 * $class::CHUNK_SIZE) break;	// would be endless
		}
		$this->assertCount(4 * $class::CHUNK_SIZE, $seen, 'without skip() getUpdated() does not end');
		$this->assertCount($class::CHUNK_SIZE, array_unique($seen), 'always the same first chunk of entries is returned');
	}

	/**
	 * @param class-string<Embedding\Base> $class
	 */
	#[DataProvider('plugins')]
	public function testSkippedEntriesDoNotBlockTheOnesBehindThem(string $class) : void
	{
		$plugin = new $class();
		$seen = [];
		foreach ($plugin->getUpdated() as $row)
		{
			$seen[] = $id = (int)$row[$class::ID];
			$plugin->skip($id);
			$this->assertLessThan(3 * self::ENTRIES, count($seen), 'getUpdated() does not end');
		}
		sort($seen);
		$this->assertSame(range(self::FIRST_ID, self::FIRST_ID + self::ENTRIES - 1), $seen, 'every entry exactly once');
	}

	/**
	 * @param class-string<Embedding\Base> $class
	 */
	#[DataProvider('plugins')]
	public function testSkipOnlyAffectsThePluginInstance(string $class) : void
	{
		$plugin = new $class();
		foreach ($plugin->getUpdated() as $row)
		{
			$plugin->skip((int)$row[$class::ID]);
		}
		// a new instance (eg. the next run of the async job) retries the skipped entries
		$ids = [];
		foreach ((new $class())->getUpdated() as $row)
		{
			$ids[] = (int)$row[$class::ID];
			if (count($ids) >= $class::CHUNK_SIZE) break;
		}
		$this->assertCount($class::CHUNK_SIZE, $ids);
	}
}
