<?php
/**
 * EGroupware RAG: Test Hooks::settings()'s static config-array
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-3 entry: pure static config-array return.
 *
 * Also locks in that "hybrid" (RAG+Fulltext meta-search) is actually offered as a user-facing
 * option for both per-app and site-default search type, per ralf's 2026-09-17 request to add
 * coverage for the various searches, hybrid/meta-search especially.
 */
class HooksSettingsTest extends Api\LoggedInTest
{
	public function testExposesAllFourSearchTypesForAddressbookAndDefaultSearch()
	{
		$settings = Hooks::settings([]);

		foreach (['addressbook_search', 'default_search'] as $name)
		{
			$this->assertArrayHasKey($name, $settings);
			$this->assertSame('select', $settings[$name]['type']);
			$this->assertEqualsCanonicalizing(['legacy', 'fulltext', 'hybrid', 'rag'],
				array_keys($settings[$name]['values']),
				"$name should offer all 4 search types, including hybrid meta-search");
		}
	}

	public function testDefaultsMatchDocumentedFallbacks()
	{
		$settings = Hooks::settings([]);

		// legacy per-app search stays the addressbook default; site-wide default is fulltext-only
		$this->assertSame('legacy', $settings['addressbook_search']['default']);
		$this->assertSame('fulltext', $settings['default_search']['default']);
	}

	public function testSearchOrderAndWordstartSettingsPresent()
	{
		$settings = Hooks::settings([]);

		$this->assertEqualsCanonicalizing(['app', 'relevance'],
			array_keys($settings['default_search_order']['values']));
		$this->assertSame('app', $settings['default_search_order']['default']);

		$this->assertEqualsCanonicalizing(['yes', 'no'],
			array_keys($settings['fulltext_match_wordstart']['values']));
		$this->assertSame('yes', $settings['fulltext_match_wordstart']['default']);
	}
}
