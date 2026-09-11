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
	// Verified against github.com/EGroupware/phpbrain: its schema (setup/tables_current.inc.php)
	// has no egw_kb_articles_extra-style custom-fields table, and its admin menu (hook_admin.inc.php)
	// has no "Custom fields" entry, so phpbrain has no custom-fields storage at all — leaving these
	// unset (Base.php's empty-string defaults) is correct, not a placeholder. This previously pointed
	// at Timesheet's egw_timesheet_extra/ts_id, leaking timesheet custom-field text into phpbrain's index.

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