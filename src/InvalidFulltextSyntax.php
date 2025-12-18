<?php
/**
 * EGroupware RAG system
 *
 * @package rag
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb@egroupware.org>
 * @copyright 2025 by Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api\Db\Exception\InvalidSql;

class InvalidFulltextSyntax extends InvalidSql
{
	public readonly $pattern;
	public function __construct(?string $msg, $code = 1064, ?\Exception $previous = null, ?string $pattern = null)
	{
		parent::__construct($msg, $code, $previous);
		$this->pattern = $pattern;
	}
}