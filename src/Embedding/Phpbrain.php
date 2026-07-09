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

namespace EGroupware\Rag\Embedding;

use EGroupware\Api;

/**
 * Plugin for KnowledgeBase articles
 */
class Phpbrain extends Base
{
	const APP = 'phpbrain';
	const TABLE = 'egw_kb_articles';
	const ID = 'art_id';
	const MODIFIED = 'modified';
	const CREATED = 'created';
	const TITLE = 'title';
	const DESCRIPTION = 'text';
	protected static $additional_cols = ['topic'];
	const NOT_DELETED = 'published>0';
	// phpbrain is not part of this checkout, so its actual custom-fields table/columns
	// (if any) can't be verified here — leave unset rather than guess, to avoid
	// querying an unrelated app's table (this previously pointed at Timesheet's
	// egw_timesheet_extra/ts_id, leaking timesheet custom-field text into phpbrain's index)

	/**
	 * Allows row-specific modifications without overwriting getUpdated()
	 * - transform html --> plaintext
	 *
	 * @param array|null $row
	 * @param bool $fulltext
	 * @return void
	 */
	protected function processRow(array &$row = null, bool $fulltext = false)
	{
		$row[self::DESCRIPTION] = trim(strip_tags($row[self::DESCRIPTION]));
	}
}