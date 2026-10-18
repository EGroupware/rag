<?php
/**
 * EGroupware RAG: Test that errors of the indexer are logged short and not once per entry
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * A full egw_rag_fulltext.ft_id made every entry of an instance fail: each error logged the whole entry (and the exception with
 * the full SQL statement) and wrote the last errors into the config, 20 times a second, and filled async.log with 3.8 GB.
 */
class EmbeddingLogErrorTest extends Api\LoggedInTest
{
	private string $log;
	private $saved_error_log;
	private $saved_errors;

	protected function setUp() : void
	{
		parent::setUp();
		new Embedding();    // loads the class
		$this->log = tempnam(sys_get_temp_dir(), 'rag-log');
		$this->saved_error_log = ini_get('error_log');
		$this->saved_errors = Api\Config::read(Embedding::APP)[Embedding::RAG_LAST_ERRORS] ?? null;
		Api\Config::save_value(Embedding::RAG_LAST_ERRORS, null, Embedding::APP);   // start empty, it keeps the last 5 errors
		(new \ReflectionProperty(Embedding::class, 'logged_errors'))->setValue(null, []);
	}

	protected function tearDown() : void
	{
		ini_set('error_log', $this->saved_error_log === false ? '' : $this->saved_error_log);
		unlink($this->log);
		Api\Config::save_value(Embedding::RAG_LAST_ERRORS, $this->saved_errors, Embedding::APP);
		parent::tearDown();
	}

	private function logAnError(int $times, string $kind='full') : void
	{
		ini_set('error_log', $this->log);   // here, not in setUp(), something resets it in between
		for ($i = 0; $i < $times; $i++)
		{
			Embedding::logError(new Api\Db\Exception("Out of range value for column 'ft_id' at row 1 ($kind): REPLACE INTO egw_rag_fulltext (ft_title) VALUES ('".
				str_repeat('x', 5000)."')"), 'infolog', true, [
					'info_id' => $i, 'info_description' => str_repeat('very long description ', 500),
				]);
		}
	}

	private function logged(string $needle) : int
	{
		return substr_count(file_get_contents($this->log), $needle);
	}

	public function testLogLinesAreShort()
	{
		$this->logAnError(1);
		$log = file_get_contents($this->log);
		$this->assertLessThan(6000, strlen($log), 'a 5kB statement and a 11kB description must not be logged in full');
		$this->assertStringContainsString("Out of range value for column 'ft_id'", $log);
		$this->assertStringContainsString('chars)', $log, 'shortened text says how much was left out');
		$this->assertStringNotContainsString(str_repeat('x', 1000), $log);
	}

	public function testTheSameErrorIsLoggedInFullOnlyThreeTimesAndThenEvery100th()
	{
		$this->logAnError(250);
		$this->assertSame(3 + 2, $this->logged(' entry='), 'first 3, then the 100th and the 200th');
		$this->assertSame(3, $this->logged('.php:'), 'a trace only for the first 3');
		$this->assertStringContainsString('(200. time in this run)', file_get_contents($this->log));
		$this->assertLessThan(60000, filesize($this->log), '250 errors are not 250 entries in the log');
	}

	public function testDifferentErrorsAreNotThrottledTogether()
	{
		$this->logAnError(10, 'one');
		$this->logAnError(10, 'two');
		$this->assertSame(6, $this->logged(' entry='), 'each kind has its own 3');
	}

	public function testConfigEntryIsLeanAndNotWrittenForEveryEntry()
	{
		$this->logAnError(1);
		$errors = Api\Config::read(Embedding::APP)[Embedding::RAG_LAST_ERRORS];
		$this->assertCount(1, $errors);
		$this->assertLessThan(20000, strlen(json_encode($errors)), 'no whole entries, statements and trace arguments');
		$this->assertLessThan(1100, strlen($errors[0]['message']));
		foreach ($errors[0]['trace'] as $frame)
		{
			$this->assertArrayNotHasKey('args', $frame);
		}
		$this->assertLessThan(1100, strlen($errors[0]['entry']['info_description']));
		$this->assertSame(0, $errors[0]['entry']['info_id'], 'short values are kept');

		// the 4th..99th of the same error do not touch the config
		$this->logAnError(2);
		$this->assertCount(3, Api\Config::read(Embedding::APP)[Embedding::RAG_LAST_ERRORS]);
		$this->logAnError(10);
		$this->assertCount(3, Api\Config::read(Embedding::APP)[Embedding::RAG_LAST_ERRORS], 'throttled errors are not stored');
	}
}
