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
 * Plugin for ProjectManager
 */
class Projectmanager extends Base
{
	const APP = 'projectmanager';
	const TABLE = 'egw_pm_projects';
	const ID = 'pm_id';
	const MODIFIED = 'pm_modified';
	const TITLE = 'pm_title';
	const DESCRIPTION = 'pm_description';
	protected static $additional_cols = ['pm_number'];
	const NOT_DELETED = "pm_status<>'deleted'";
	const EXTRA_TABLE = 'egw_pm_extra';
	const EXTRA_ID = 'pm_id';
	const EXTRA_NAME = 'pm_extra_name';
	const EXTRA_VALUE = 'pm_extra_value';

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