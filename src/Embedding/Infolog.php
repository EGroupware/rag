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
 * Plugin for InfoLog
 */
class Infolog extends Base
{
	const APP = 'infolog';
	const TABLE = 'egw_infolog';
	const ID = 'info_id';
	const MODIFIED = 'info_datemodified';
	const MODIFIED_TYPE = 'int';
	const TITLE = 'info_subject';
	const DESCRIPTION = 'info_des';
	protected static $additional_cols = ['info_location','info_from'];
	const NOT_DELETED = "info_status<>'deleted'";
	const EXTRA_TABLE = 'egw_infolog_extra';
	const EXTRA_ID = 'info_id';
	const EXTRA_NAME = 'info_extra_name';
	const EXTRA_VALUE = 'info_extra_value';

	/**
	 * Allows row-specific modifications without overwriting getUpdated()
	 * - do NOT index description, if PGP encrypted
	 *
	 * @param array|null $row
	 * @param bool $fulltext
	 * @return void
	 */
	protected function processRow(array &$row=null, bool $fulltext=false)
	{
		// makes no sense to calculate embeddings for PGP encrypted descriptions
		if (str_starts_with($row[self::DESCRIPTION], '-----BEGIN PGP MESSAGE-----'))
		{
			$row[self::DESCRIPTION]='';
		}
	}

	/**
	 * Reimplemented as InfoLog aliases egw_infolog as main
	 *
	 * @return string table-name
	 */
	public function table()
	{
		return 'main';
	}
}