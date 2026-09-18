<?php
/**
 * EGroupware RAG: Test ApiHandler's REST surface - put/post/delete, read(), and
 * _report_filters()'s JSON-filter/nresults/multiget parsing
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
 * doc/ai/projects/rag-test-coverage.md's priority-6 entry (the REST API's remaining, tractable
 * slice). get()/propfind()/propfind_generator() are NOT covered here - they pull in
 * Api\CalDAV\Handler's etag handling, method2acl mapping, and a full ACL-filtered search()
 * result, which genuinely needs a full CalDAV test harness (the reason this whole area was
 * originally deferred), not a partial mock.
 *
 * All tests build the handler via ReflectionClass::newInstanceWithoutConstructor() (the real
 * constructor needs a live Api\CalDAV instance) and inject only what each method actually
 * touches.
 */
class ApiHandlerRestSurfaceTest extends Api\LoggedInTest
{
	private array $restoreServer = [];

	protected function tearDown() : void
	{
		foreach ($this->restoreServer as $key => $value)
		{
			if ($value === null)
			{
				unset($_SERVER[$key]);
			}
			else
			{
				$_SERVER[$key] = $value;
			}
		}
		$this->restoreServer = [];
		parent::tearDown();
	}

	private function setServer(string $key, $value) : void
	{
		if (!array_key_exists($key, $this->restoreServer))
		{
			$this->restoreServer[$key] = $_SERVER[$key] ?? null;
		}
		$_SERVER[$key] = $value;
	}

	private function handler() : ApiHandler
	{
		return (new ReflectionClass(ApiHandler::class))->newInstanceWithoutConstructor();
	}

	private function handlerWithBo(Embedding $bo) : ApiHandler
	{
		$handler = $this->handler();
		$property = new ReflectionProperty(ApiHandler::class, 'bo');
		$property->setAccessible(true);
		$property->setValue($handler, $bo);
		return $handler;
	}

	public function testPutPostDeleteAreAlwaysForbidden()
	{
		$handler = $this->handler();
		$options = [];

		$this->assertSame('403 Forbidden', $handler->put($options, 1));
		$this->assertSame('403 Forbidden', $handler->post($options, 1));
		$this->assertSame('403 Forbidden', $handler->delete($options, 1, 0));
	}

	public function testReadReturnsBoReadResult()
	{
		$bo = new class extends Embedding {
			public function read($id, bool $check_acl=false)
			{
				return ['app' => 'addressbook', 'app_id' => 123, 'title' => 'found it'];
			}
		};
		$handler = $this->handlerWithBo($bo);

		$this->assertSame(['app' => 'addressbook', 'app_id' => 123, 'title' => 'found it'],
			$handler->read('addressbook:123'));
	}

	public function testReadCatchesNoPermissionAndReturnsFalse()
	{
		$bo = new class extends Embedding {
			public function read($id, bool $check_acl=false)
			{
				throw new Api\Exception\NoPermission();
			}
		};
		$handler = $this->handlerWithBo($bo);

		$this->assertFalse($handler->read('addressbook:123'));
	}

	public function testReportFiltersMergesJsonFilter()
	{
		$this->setServer('REQUEST_METHOD', 'GET');
		$this->setServer('REQUEST_URI', '/groupdav.php/ralf/rag/');
		$this->setServer('HTTP_ACCEPT', 'application/json');
		unset($_SERVER['HTTP_CONTENT_TYPE']);

		$handler = $this->handler();
		$filters = [];
		$nresults = null;
		$ok = $handler->_report_filters(
			['filters' => ['search' => 'some pattern'], 'other' => [], 'root' => ['name' => 'REPORT']],
			$filters, '', $nresults, 0);

		$this->assertTrue($ok);
		$this->assertSame('some pattern', $filters['search']);
		$this->assertSame('search', $filters['type']);   // 'hybrid' => Embedding::search()
		$this->assertNull($nresults);
	}

	public function testReportFiltersParsesNresultsLimit()
	{
		$this->setServer('REQUEST_METHOD', 'GET');
		$this->setServer('REQUEST_URI', '/groupdav.php/ralf/rag/');
		$this->setServer('HTTP_ACCEPT', 'application/json');
		unset($_SERVER['HTTP_CONTENT_TYPE']);

		$handler = $this->handler();
		$filters = [];
		$nresults = null;
		$handler->_report_filters(
			[
				'filters' => ['search' => 'some pattern'],
				'other' => [['name' => 'nresults', 'data' => '5']],
				'root' => ['name' => 'REPORT'],
			],
			$filters, '', $nresults, 0);

		$this->assertSame(5, $nresults);
	}

	public function testReportFiltersParsesAddressbookMultigetHrefs()
	{
		$this->setServer('REQUEST_METHOD', 'GET');
		$this->setServer('REQUEST_URI', '/groupdav.php/ralf/rag/');
		$this->setServer('HTTP_ACCEPT', 'application/json');
		unset($_SERVER['HTTP_CONTENT_TYPE']);

		$handler = $this->handler();
		$filters = [];
		$nresults = null;
		$handler->_report_filters(
			[
				'filters' => ['search' => 'some pattern'],
				'other' => [
					['name' => 'href', 'data' => '/groupdav.php/ralf/rag/addressbook%3A123'],
					['name' => 'href', 'data' => '/groupdav.php/ralf/rag/calendar%3A456'],
				],
				'root' => ['name' => 'addressbook-multiget'],
			],
			$filters, '', $nresults, 0);

		$this->assertSame(['addressbook:123', 'calendar:456'], $handler->requested_multiget_ids);
		$this->assertSame(['addressbook:123', 'calendar:456'], $filters['id']);
	}
}
