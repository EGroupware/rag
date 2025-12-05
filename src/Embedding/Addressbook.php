<?php
/**
 * EGroupware RAG system
 *
 * @package addressbook
 * @subpackage rag
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb@egroupware.org>
 * @copyright 2025 by Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Addressbook;

use EGroupware\Api;
use EGroupware\Rag\Embedding\Base;

/**
 * Plugin for Addressbook
 */
class Rag extends Base
{
	const APP = 'addressbook';
	const TABLE = 'egw_addressbook';
	const ID = 'contact_id';
	const MODIFIED = 'contact_modified';
	const TITLE = 'n_fileas';
	const DESCRIPTION = 'contact_note';
	const NOT_DELETED = "contact_tid<>'D'";
	const EXTRA_TABLE = 'egw_addressbook_extra';
	const EXTRA_ID = 'contact_id';
	const EXTRA_NAME = 'contact_name';
	const EXTRA_VALUE = 'contact_value';

	static ?array $text_cols=[];

	/**
	 * Get all text-fields with content to fulltext-index
	 */
	public static function initStatic()
	{
		foreach($GLOBALS['egw']->db->get_table_definitions('api', self::TABLE)['fd'] as $col => $data)
		{
			if (($data['type'] === 'text' || $data['type'] === 'varchar' && $data['precision'] > 8) &&
				!str_starts_with($col, 'tel_') &&
				!in_array($col, [self::TITLE, self::DESCRIPTION, 'contact_uid', 'carddav_name', 'contact_pubkey',
					'contact_freebusy_uri', 'contact_calendar_uri', 'contact_tz', 'contact_geo']))
			{
				self::$text_cols[] = $col;
			}
		}
	}

	/**
	 * Get updated entries
	 *
	 * @param bool $fulltext false: check the rag, true: check fulltext index
	 * @param ?array $hook_data null or data from notify-all hook, to just emit this entry
	 * @return \Generator<array>
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function getUpdated(bool $fulltext=false, ?array $hook_data=null)
	{
		$where = [
			self::NOT_DELETED, // no need to embed deleted entries
		];
		$cols = array_merge([self::ID, self::TITLE, self::DESCRIPTION], self::$text_cols);
		// RAG makes only sense for a long note
		if (!$fulltext)
		{
			$where[] = 'LENGTH(contact_note)>50';
			$cols = [self::ID, 'contact_note'];
		}
		// check / process hook-data to not query entry again, if already contained
		if ($hook_data && $hook_data['app'] === self::APP && !empty($hook_data['id']))
		{
			$where[self::ID] = $hook_data['id'];
			$entries = self::getRowFromNotifyHookData($hook_data, $cols);
		}
		$join = $this->getJoin('int', $where, $fulltext);
		do
		{
			$r = 0;
			foreach ($entries ?? $this->db->select(self::TABLE, $cols,
				$where, __LINE__, __FILE__, 0, 'ORDER BY ' . self::MODIFIED . ' ASC', '',
				self::CHUNK_SIZE, $join) as $row)
			{
				$row = $this->getExtraTexts($row[self::ID], $row, $hook_data['data']??null);
				++$r;
				yield $row;
			}
		} while ($r === self::CHUNK_SIZE);
	}
}
Rag::initStatic();