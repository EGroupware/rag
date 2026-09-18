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

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-6 entry.
 *
 * Two real bugs found while adding this coverage, both fixed:
 *
 * 1. `static $cfs=null;` inside getExtraTexts() (defined only in the abstract Base class, never
 *    overridden) is bound to the compiled method body, not per calling subclass - confirmed
 *    with a minimal PHP repro. embed()'s normal per-app loop calls
 *    getUpdated()->getExtraTexts() for every app plugin in ONE process, so the first app
 *    processed poisoned every other app's custom-field lookup for the rest of that run. Fixed
 *    by keying the static cache array by static::APP.
 *
 * 2. The hook-data fast path's inclusion condition was inverted:
 *    `!empty($v) && !trim($v)` is true only when $v is non-empty AND trims to '' (i.e. only
 *    whitespace-only values were included; real text content was excluded). Fixed to
 *    `!empty($v) && trim($v) !== ''`.
 *
 * Both cases are covered by ONE test method deliberately: getExtraTexts()'s `static $cfs`
 * cache is a process-lifetime cache with no way to reset it from outside (it's a method-local
 * static, not a reflectable class property), so splitting these into separate test methods
 * made the second one flaky/order-dependent on whatever the first method had already cached.
 *
 * Uses 'timesheet' and 'calendar' rather than 'addressbook' - deliberately: several OTHER test
 * files in this suite (EmbeddingEmbedUpsertTest, EmbeddingReadTest) already exercise real
 * addressbook contacts through embed()/getUpdated(), which warms getExtraTexts()'s per-app
 * cache slot for 'addressbook' BEFORE this test's own custom field exists - a real
 * demonstration of the caching design's process-lifetime staleness (a distinct, narrower
 * limitation from the cross-app bug fixed here: even per-app-keyed, the cache is never
 * invalidated if a custom field is added/changed later in the same process). 'timesheet' and
 * 'calendar' aren't touched by any other test file's getExtraTexts() calls, so this test's own
 * ordering (process A - the "poisoner" - before B - the "victim") is what's actually observed.
 */
class EmbeddingGetExtraTextsTest extends Api\LoggedInTest
{
	private array $cfsToClean = [];   // [app, name] pairs
	private array $extraRowsToClean = [];   // [table, where, app] tuples

	protected function tearDown() : void
	{
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
