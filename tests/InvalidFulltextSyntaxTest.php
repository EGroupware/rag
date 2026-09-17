<?php
/**
 * EGroupware RAG: Test InvalidFulltextSyntax's message-rewriting constructor
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-3 entry: 100% pure, zero setup beyond lang().
 */
class InvalidFulltextSyntaxTest extends Api\LoggedInTest
{
	public function testRewritesMariaDbSyntaxErrorMessage()
	{
		$e = new InvalidFulltextSyntax(
			'syntax error, unexpected \'@\', expecting $end (1064)', 1064, null, 'foo@bar');

		$expected = lang('Syntax error in fulltext search-pattern').": 'foo@bar' ".
			str_replace('unexpected', lang('unexpected'), 'unexpected \'@\', expecting $end');
		$this->assertSame($expected, $e->getMessage());
		$this->assertSame('foo@bar', $e->pattern);
		$this->assertSame(1064, $e->getCode());
	}

	public function testFallsBackToRawMessageWhenNotAMariaDbSyntaxError()
	{
		$e = new InvalidFulltextSyntax('some other DB error', 1064, null, 'the-pattern');

		$expected = lang('Syntax error in fulltext search-pattern').": 'the-pattern'\nsome other DB error";
		$this->assertSame($expected, $e->getMessage());
		$this->assertSame('the-pattern', $e->pattern);
	}

	public function testIsAnInvalidSql()
	{
		$e = new InvalidFulltextSyntax('x', 1064, null, 'p');
		$this->assertInstanceOf(Api\Db\Exception\InvalidSql::class, $e);
	}
}
