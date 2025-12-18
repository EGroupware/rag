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
	public readonly string $pattern;
	public function __construct(?string $msg, $code = 1064, ?\Exception $previous = null, ?string $pattern = null)
	{
		$this->pattern = $pattern;
		if (preg_match('/^syntax error, (.*) \(1064\)/mi', $msg, $matches))
		{
			$msg = lang('Syntax error in fulltext search-pattern').": '".$pattern."' ".str_replace('unexpected', lang('unexpected'), $matches[1]);
		}
		else
		{
			$msg = lang('Syntax error in fulltext search-pattern').": '".$pattern."'\n".$msg;
		}
		parent::__construct($msg, $code, $previous);
	}
}