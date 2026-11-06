<?php
/**
 * EGroupware RAG: Test Embedding's pure static/protected-static SQL-fragment helpers
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
 * doc/ai/projects/rag-test-coverage.md's priority-3 entry: distanceById()/orderByIds() (public
 * static) and validateOrder() (protected static, called via reflection).
 */
class EmbeddingStaticHelpersTest extends Api\LoggedInTest
{
	public function testDistanceByIdBuildsCaseExpression()
	{
		$sql = Embedding::distanceById([12 => 0.1, 34 => 0.25], 'contact_id');

		$this->assertSame("CASE contact_id  WHEN 12 THEN 0.1 WHEN 34 THEN 0.25 END", $sql);
	}

	public function testDistanceByIdHandlesEmptyResultWithoutSqlError()
	{
		$sql = Embedding::distanceById([], 'contact_id');

		// synthesizes a harmless "id 0 -> distance 1" row instead of an empty/invalid CASE
		$this->assertSame("CASE contact_id  WHEN 0 THEN 1 END", $sql);
	}

	public function testDistanceByIdCastsIdsAndDistancesNumerically()
	{
		// non-numeric/injected-looking keys must be cast away, not interpolated verbatim
		$sql = Embedding::distanceById(['12; DROP TABLE x' => '0.5abc'], 'contact_id');

		$this->assertSame("CASE contact_id  WHEN 12 THEN 0.5 END", $sql);
	}

	public function testOrderByIdsPreservesOriginalKeyOrder()
	{
		// order-by-position: the first key must sort first (position 0), etc.
		$sql = Embedding::orderByIds([12 => 0.9, 34 => 0.1, 56 => 0.5], 'contact_id');

		$this->assertSame("CASE contact_id  WHEN 12 THEN 0 WHEN 34 THEN 1 WHEN 56 THEN 2 END", $sql);
	}

	private function validateOrder(string $order, ?string $notModified=null) : string
	{
		$method = new ReflectionMethod(Embedding::class, 'validateOrder');
		$method->setAccessible(true);
		return $method->invoke(null, $order, $notModified);
	}

	public function testValidateOrderDefaultsToDefaultAscOnInvalidInput()
	{
		$this->assertSame('default ASC', $this->validateOrder('bogus'));
	}

	public function testValidateOrderAcceptsKnownOrderWithExplicitSort()
	{
		$this->assertSame('modified DESC', $this->validateOrder('modified DESC'));
		$this->assertSame('distance ASC', $this->validateOrder('distance'));
	}

	public function testValidateOrderLeavesModifiedAlone()
	{
		// $not_modified only kicks in for anything OTHER than "modified"
		$this->assertSame('modified ASC', $this->validateOrder('modified', 'relevance'));
	}

	public function testValidateOrderSubstitutesNotModifiedWithoutInversion()
	{
		// searchEmbeddings()/searchFulltext() call validateOrder($order, 'distance'/'relevance')
		// to translate the caller-facing "default" into their own default ranking column
		$this->assertSame('distance ASC', $this->validateOrder('default', 'distance'));
	}

	public function testValidateOrderInvertsWhenNotModifiedHasBangPrefix()
	{
		// used by search()'s hybrid merge: "!relevance" means "distance's natural order is
		// ascending (closer=better), but relevance's is descending (higher=better)"
		$this->assertSame('relevance DESC', $this->validateOrder('default', '!relevance'));
		$this->assertSame('relevance ASC', $this->validateOrder('default DESC', '!relevance'));
	}

	public function testValidateOrderKeepsOrderUnchangedWhenAlreadyMatchingNotModified()
	{
		$this->assertSame('distance DESC', $this->validateOrder('distance DESC', 'distance'));
	}
}
