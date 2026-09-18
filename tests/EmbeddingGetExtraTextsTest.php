<?php
/**
 * EGroupware RAG: Test Embedding\Base::getExtraTexts()'s per-app custom-field cache and
 * hook-data fast-path value filtering
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use ReflectionMethod;
use ReflectionProperty;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-6 entry.
 *
 * Two real bugs found while adding this coverage, both fixed:
 *
 * 1. getExtraTexts()'s custom-fields cache was originally a method-local `static $cfs=null;`.
 *    Since getExtraTexts() is defined only in the abstract Base class and never overridden, a
 *    method-local static there is bound to the compiled method body, not per calling subclass
 *    (confirmed with a minimal PHP repro) - embed()'s normal per-app loop calls
 *    getUpdated()->getExtraTexts() for every app plugin in ONE process, so the first app
 *    processed poisoned every other app's custom-field lookup for the rest of that run.
 *    Fixed by moving the cache to Base::$textCustomFieldsCache, a real private static class
 *    property keyed by static::APP - not just a method-local static keyed the same way, since
 *    that would still have no reflectable/resettable seam for tests (an earlier version of
 *    this test tried to work around that by picking 'timesheet'/'calendar' hoping no other
 *    test would touch them first, which turned out to still be flaky once the real, full
 *    `Apps` CI testsuite runs every app's tests together - some other app's own fixture can
 *    trigger RAG's notify-hook and warm the cache first regardless of which app is picked).
 *    setUp()/tearDown() below reset then restore this property directly, which is the only
 *    actually deterministic fix.
 *
 * 2. The hook-data fast path's inclusion condition was inverted:
 *    `!empty($v) && !trim($v)` is true only when $v is non-empty AND trims to '' (i.e. only
 *    whitespace-only values were included; real text content was excluded). Fixed to
 *    `!empty($v) && trim($v) !== ''`.
 *
 * Both cases are covered by ONE test method: with the cache reset per-test, they could be
 * split, but keeping the "assert per-app isolation" and "assert hook-data filtering" checks
 * together in one realistic scenario (create a field, index some data, read it back both ways)
 * reads more like how the method is actually used.
 */
class EmbeddingGetExtraTextsTest extends Api\LoggedInTest
{
	private array $cfsToClean = [];   // [app, name] pairs
	private array $extraRowsToClean = [];   // [table, where, app] tuples
	private ?array $originalCache = null;

	private function cacheProperty() : ReflectionProperty
	{
		$property = new ReflectionProperty(Embedding\Base::class, 'textCustomFieldsCache');
		$property->setAccessible(true);
		return $property;
	}

	protected function setUp() : void
	{
		parent::setUp();
		$property = $this->cacheProperty();
		$this->originalCache = $property->getValue();
		$property->setValue(null, []);   // deterministic clean slate, regardless of what ran before
	}

	protected function tearDown() : void
	{
		$this->cacheProperty()->setValue(null, $this->originalCache);
		$this->originalCache = null;

		$db = $GLOBALS['egw']->db;
		foreach ($this->extraRowsToClean as [$table, $where, $app])
		{
			$db->delete($table, $where, __LINE__, __FILE__, $app);
		}
		$this->extraRowsToClean = [];
		foreach ($this->cfsToClean as [$app, $name])
		{
			$db->delete('egw_customfields', ['cf_app' => $app, 'cf_name' => $name], __LINE__, __FILE__, 'api');
		}
		$this->cfsToClean = [];
		parent::tearDown();
	}

	private function addTextCustomField(string $app, string $name) : void
	{
		Api\Storage\Customfields::update([
			'id' => 0, 'name' => $name, 'app' => $app, 'label' => 'RAG test CF', 'type' => 'text',
			'order' => 999999, 'type2' => null, 'help' => '', 'values' => null, 'len' => null,
			'rows' => null, 'tab' => null, 'needed' => false, 'private' => null, 'readonly' => null,
		]);
		$this->cfsToClean[] = [$app, $name];
	}

	private function callGetExtraTexts(Embedding\Base $plugin, int $id, ?array $hookData = null) : array
	{
		$method = new ReflectionMethod(get_class($plugin), 'getExtraTexts');
		$method->setAccessible(true);
		return $method->invoke($plugin, $id, [], $hookData);
	}

	public function testCustomFieldCacheIsPerAppAndHookDataFilteringIsCorrect()
	{
		$tsField = 'ragtestts'.substr(uniqid(), -8);
		$calField = 'ragtestcal'.substr(uniqid(), -8);
		$this->addTextCustomField('timesheet', $tsField);
		$this->addTextCustomField('calendar', $calField);

		// --- bug 1: per-app cache isolation ---
		$tsId = 555001;
		$calId = 555002;
		$db = $GLOBALS['egw']->db;
		$db->insert('egw_timesheet_extra', [
			'ts_id' => $tsId, 'ts_extra_name' => $tsField, 'ts_extra_value' => 'TIMESHEET-VALUE',
		], false, __LINE__, __FILE__, 'timesheet');
		$this->extraRowsToClean[] = ['egw_timesheet_extra', ['ts_id' => $tsId, 'ts_extra_name' => $tsField], 'timesheet'];
		$db->insert('egw_cal_extra', [
			'cal_id' => $calId, 'cal_extra_name' => $calField, 'cal_extra_value' => 'CALENDAR-VALUE',
		], false, __LINE__, __FILE__, 'calendar');
		$this->extraRowsToClean[] = ['egw_cal_extra', ['cal_id' => $calId, 'cal_extra_name' => $calField], 'calendar'];

		// process timesheet FIRST, so a shared (unkeyed) cache would poison calendar's lookup
		$tsResult = $this->callGetExtraTexts(new Embedding\Timesheet(), $tsId);
		$this->assertSame('TIMESHEET-VALUE', $tsResult[$tsField] ?? null);

		$calResult = $this->callGetExtraTexts(new Embedding\Calendar(), $calId);
		$this->assertSame('CALENDAR-VALUE', $calResult[$calField] ?? null,
			"calendar's own custom-field value must be found - a shared cache would have kept ".
			"timesheet's field list, silently querying egw_cal_extra for the wrong field name");

		// --- bug 2: hook-data fast path value filtering ---
		// getExtraTexts()'s hook-data branch requires ALL of the app's text/htmlarea custom
		// fields to be present as keys (else it falls back to the DB-query path instead) -
		// build hook-data covering all of timesheet's (a harmless empty placeholder for any
		// others), in case this shared dev instance already has some configured
		$textCfs = array_filter(Api\Storage\Customfields::get('timesheet'),
			static fn($cf) => in_array($cf['type'], ['text', 'htmlarea']));
		$baseHookData = array_fill_keys(array_map(fn($name) => '#'.$name, array_keys($textCfs)), '');

		$plugin = new Embedding\Timesheet();
		$hookResult = $this->callGetExtraTexts($plugin, 555003,
			['#'.$tsField => 'genuinely meaningful text'] + $baseHookData);
		$this->assertSame('genuinely meaningful text', $hookResult[$tsField] ?? null,
			'real, non-whitespace hook-data content must be included');

		$whitespaceResult = $this->callGetExtraTexts($plugin, 555004,
			['#'.$tsField => '   '] + $baseHookData);
		$this->assertArrayNotHasKey($tsField, $whitespaceResult,
			'a whitespace-only hook-data value must NOT be included');
	}
}
