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

use EGroupware\Api;


/**
 * diverse hooks as static methods
 *
 */
class Hooks
{
	const APP = 'rag';

	/**
	 * Hooks to build RAGs sidebox-menu plus the admin and Api\Preferences sections
	 *
	 * @param string|array $args hook args
	 */
	static function allHooks($args)
	{
		$appname = self::APP;
		$location = is_array($args) ? $args['location'] : $args;

		if ($location == 'sidebox_menu')
		{

		}

		if ($GLOBALS['egw_info']['user']['apps']['admin'])
		{
			$file = Array(
				'Site Configuration' => Api\Egw::link('/index.php','menuaction=admin.admin_config.index&appname=' . $appname,'&ajax=true'),
			);
			if ($location == 'admin')
			{
				display_section($appname,$file);
			}
			else
			{
				//$GLOBALS['egw']->framework->sidebox($appname, lang('Configuration'), $file);
			}
		}
	}

	/**
	 * Hook to overwrite config and/or set "sel_options"
	 *
	 * @param array $data
	 */
	public static function config($data)
	{
		if (($errors = Api\Config::read(self::APP)[Embedding::RAG_LAST_ERRORS] ?? []))
		{
			$last_error = current($errors);
			return [
				'rag_last_error_time' => Api\DateTime::to($last_error['date']).': '.$last_error['message'],
				'rag_last_errors' => json_encode($errors, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
			];
		}
	}
}