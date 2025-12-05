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
 * Plugin for Timesheet
 */
class Calendar extends Base
{
	const APP = 'calendar';
	const TABLE = 'egw_cal';
	const ID = 'cal_id';
	const MODIFIED = 'cal_modified';
	const MODIFIED_TYPE = 'int';
	const TITLE = 'cal_title';
	const DESCRIPTION = 'cal_description';
	protected static $additional_cols = ['cal_location'];
	const NOT_DELETED = 'cal_deleted IS NOT NULL';
	const EXTRA_TABLE = 'egw_cal_extra';
	const EXTRA_ID = 'cal_id';
	const EXTRA_NAME = 'cal_extra_name';
	const EXTRA_VALUE = 'cal_extra_value';
}