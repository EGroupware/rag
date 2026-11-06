<?php
/**
 * EGroupware RAG: Test ApiHandler's pure/zero-setup logic - check_access(), filter2col_filter(),
 * handleException()
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use ReflectionClass;
use ReflectionMethod;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-3 entry.
 *
 * All three methods tested here are public REST-API surface (external clients hit them via
 * ApiHandler) and none of them need $this or a real Api\CalDAV instance, so every test builds
 * the handler via ReflectionClass::newInstanceWithoutConstructor() instead of the real
 * constructor (which requires a live Api\CalDAV object).
 *
 * check_access() is the exact method PR #4 found copy-pasted from an Invoices REST handler
 * (checking apps['invoices'] instead of apps['rag']) - this pins the correct app down.
 */
class ApiHandlerPureLogicTest extends Api\LoggedInTest
{
	private function handler() : ApiHandler
	{
		return (new ReflectionClass(ApiHandler::class))->newInstanceWithoutConstructor();
	}

	private function callProtected(string $method, array $args)
	{
		$ref = new ReflectionMethod(ApiHandler::class, $method);
		$ref->setAccessible(true);
		return $ref->invokeArgs($this->handler(), $args);
	}

	public function testCheckAccessGatesOnRagAppRunRight()
	{
		$saved = $GLOBALS['egw_info']['user']['apps']['rag'] ?? null;
		try
		{
			$GLOBALS['egw_info']['user']['apps']['rag'] = true;
			$this->assertTrue($this->handler()->check_access(Api\Acl::READ, 123));

			unset($GLOBALS['egw_info']['user']['apps']['rag']);
			$this->assertFalse($this->handler()->check_access(Api\Acl::READ, 123));
		}
		finally
		{
			if ($saved !== null)
			{
				$GLOBALS['egw_info']['user']['apps']['rag'] = $saved;
			}
			else
			{
				unset($GLOBALS['egw_info']['user']['apps']['rag']);
			}
		}
	}

	public function testFilter2colFilterDefaultsToHybridSearch()
	{
		$result = $this->callProtected('filter2col_filter', [['search' => 'foo']]);

		$this->assertSame('foo', $result['search']);
		$this->assertSame('search', $result['type']);   // 'hybrid' => Embedding::search()
		$this->assertNull($result['apps']);
	}

	public function testFilter2colFilterResolvesExplicitTypeAndApps()
	{
		$result = $this->callProtected('filter2col_filter', [[
			'search' => 'foo', 'type' => 'rag', 'apps' => 'addressbook',
		]]);

		$this->assertSame('searchEmbeddings', $result['type']);
		$this->assertSame(['addressbook'], $result['apps']);
	}

	public function testFilter2colFilterRejectsTooShortSearch()
	{
		$this->expectException(\Exception::class);
		$this->callProtected('filter2col_filter', [['search' => 'ab']]);
	}

	public function testFilter2colFilterRejectsMissingSearch()
	{
		$this->expectException(\Exception::class);
		$this->callProtected('filter2col_filter', [[]]);
	}

	public function testFilter2colFilterRejectsUnknownType()
	{
		$this->expectException(\Exception::class);
		$this->callProtected('filter2col_filter', [['search' => 'foo', 'type' => 'bogus']]);
	}

	private function handleException(\Throwable $e, ?string $code=null) : array
	{
		ob_start();
		$returned = $this->callProtected('handleException', [$e, $code]);
		$json = json_decode(ob_get_clean(), true);
		return [$json, $returned];
	}

	public function testNoPermissionMapsTo403()
	{
		[$json, $returned] = $this->handleException(new Api\Exception\NoPermission('nope'));

		$this->assertSame(403, $json['error']);
		$this->assertSame('Forbidden', $json['message']);
		$this->assertStringStartsWith('403 ', $returned);
	}

	public function testNotFoundMapsTo404()
	{
		[$json, $returned] = $this->handleException(new Api\Exception\NotFound('no such id'));

		$this->assertSame(404, $json['error']);
		$this->assertSame('Not Found', $json['message']);
		$this->assertStringStartsWith('404 ', $returned);
	}

	public function testDuplicateKeyInvalidSqlMapsTo409()
	{
		$e = new Api\Db\Exception\InvalidSql(
			"Duplicate entry '*cache*-0-746' for key 'egw_rag_app_app_id_chunk' (1062)", 1062);

		[$json, $returned] = $this->handleException($e);

		$this->assertSame(409, $json['error']);
		$this->assertStringStartsWith('Duplicate entry', $json['message']);
		$this->assertStringStartsWith('409 ', $returned);
	}

	public function testOtherInvalidSqlIsNotRemappedTo409()
	{
		$e = new Api\Db\Exception\InvalidSql('You have an error in your SQL syntax', 1064);

		[$json, $returned] = $this->handleException($e);

		// the JSON body carries the exception's raw code, but the returned "status line"-style
		// string clamps to 500 whenever that code isn't itself a plausible HTTP status (400-599)
		$this->assertSame(1064, $json['error']);
		$this->assertStringStartsWith('500 ', $returned);
	}

	public function testGenericExceptionKeepsItsOwnCode()
	{
		[$json, $returned] = $this->handleException(new \Exception('teapot', 418));

		$this->assertSame(418, $json['error']);
		$this->assertSame('teapot', $json['message']);
		$this->assertStringStartsWith('418 ', $returned);
	}

	public function testExplicitCodeOverridesExceptionCode()
	{
		[$json, $returned] = $this->handleException(new \Exception('whatever', 418), '400');

		$this->assertSame('400', $json['error']);
		$this->assertStringStartsWith('400 ', $returned);
	}
}
