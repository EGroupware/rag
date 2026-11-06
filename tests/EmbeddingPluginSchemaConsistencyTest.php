<?php
/**
 * EGroupware RAG: Test that every plugin's table/column constants name real schema columns
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use ReflectionClass;
use ReflectionProperty;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-2 entry.
 *
 * This bug class has already bitten the RAG codebase twice, both copy-paste-from-another-app
 * leftovers found and fixed in PR #4: Embedding\Phpbrain's custom-fields constants pointed at
 * Timesheet's egw_timesheet_extra/ts_id (leaking timesheet custom-field text into phpbrain's
 * index), and ApiHandler::check_access() checked apps['invoices'] instead of apps['rag']. This
 * test guards the Embedding\Base contract side of that bug class: every plugin's TABLE/ID/
 * MODIFIED/CREATED/TITLE/DESCRIPTION/additional_cols/EXTRA_* constants must actually name a
 * real column of the table they claim to belong to. It would have caught the original
 * Phpbrain/Timesheet mixup automatically. It does NOT check subclass-own constants outside
 * Base's declared contract (e.g. Tracker's REPLIES_TABLE/REPLY_ID/REPLY_MESSAGE).
 */
class EmbeddingPluginSchemaConsistencyTest extends Api\LoggedInTest
{
	public function testPluginConstantsNameRealColumns()
	{
		$plugins = Embedding::plugins(null);
		$this->assertNotEmpty($plugins, 'expected Embedding::plugins() to discover at least one plugin');

		$checked = 0;
		foreach ($plugins as $app => $class)
		{
			if (!is_subclass_of($class, Embedding\Base::class))
			{
				continue;   // an app's own EGroupware\<App>\Rag class, not one of our Embedding\* plugins
			}
			$checked++;

			$this->assertColumnsExist($class, $class::TABLE, array_filter([
				$class::ID, $class::MODIFIED, $class::CREATED, $class::TITLE, $class::DESCRIPTION,
			]));

			$property = new ReflectionProperty($class, 'additional_cols');
			$property->setAccessible(true);
			if ($additional = $property->getValue())
			{
				$this->assertColumnsExist($class, $class::TABLE, $additional);
			}

			if ($class::EXTRA_TABLE)
			{
				// Base.php's own docblock: EXTRA_ID "should be identical to ID". This is the
				// invariant that actually catches a wrong-app custom-fields table copy-pasted
				// wholesale (e.g. the original Phpbrain/Timesheet mixup): egw_timesheet_extra
				// and ts_id are real, valid, matching columns of a real table - just the wrong
				// app's - so a plain "does this column exist" check does NOT catch that case.
				$this->assertSame($class::ID, $class::EXTRA_ID,
					"$class: EXTRA_ID should be identical to ID per Embedding\\Base's contract - ".
					"a mismatch means EXTRA_TABLE is likely copy-pasted from a different app");

				$this->assertColumnsExist($class, $class::EXTRA_TABLE, array_filter([
					$class::EXTRA_ID, $class::EXTRA_NAME, $class::EXTRA_VALUE,
				]));
			}
		}
		$this->assertGreaterThanOrEqual(6, $checked,
			'expected to have actually checked the known Embedding\\* plugins, not silently skipped them all');
	}

	/**
	 * Looks the table up across ALL apps' schemas (Db::get_table_definitions(true, $table)),
	 * not just $class::APP's own - some plugin tables are historically owned by a different
	 * app's setup/tables_current.inc.php (e.g. egw_addressbook is defined under 'api', not
	 * 'addressbook'), so assuming schema-ownership === the plugin's APP constant is wrong.
	 */
	private function assertColumnsExist(string $class, string $table, array $columns) : void
	{
		$fd = $GLOBALS['egw']->db->get_table_definitions(true, $table)['fd'] ?? null;
		$this->assertIsArray($fd, "$class: no app's schema defines table '$table'");
		foreach ($columns as $column)
		{
			$this->assertArrayHasKey($column, $fd,
				"$class: column '$column' does not exist in table '$table' - copy-paste leftover?");
		}
	}
}
