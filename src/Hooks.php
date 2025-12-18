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
			// shorten long error-messages, specially SQL errors contain
			if (strlen($last_error['message']) > 100)
			{
				[$message, $message2] = explode("\n", $last_error['message'], 2)+[null,null];
				$last_error['message'] = substr($message, 0, 100).(strlen($message) > 100 ? '...' : '');
				if (!empty($message2))
				{
					$last_error['message'] .= "\n".substr($message2, 0, 100).(strlen($message2) > 100 ? '...' : '');
				}
			}
			return [
				'rag_last_error_time' => Api\DateTime::to($last_error['date']).': '.$last_error['message'],
				'rag_last_errors' => json_encode($errors, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
			];
		}
	}

	/**
	 * Hook to validate app configuration
	 *
	 * @param array $data
	 * @todo check url & api_key by e.g. querying the available models
	 */
	public static function configValidate($data)
	{
		$error = null;
		if (!empty($data['url']))
		{
			try {
				// todo
			}
			catch (\Exception $e) {
				$error = $e->getMessage();
			}
		}
		if ($error)
		{
			Api\Etemplate::set_validation_error('url', $error, 'newsettings');
			$GLOBALS['config_error'] = implode("\n", $error);
		}
		else
		{
			Embedding::installAsyncJob();
		}
	}

	public static function settings(array $data) : array
	{
		return [
			'default_search' => [
				'type'    => 'select',
				'label'   => 'What type of search to use for search in the apps',
				'name'    => 'default_search',
				'values'  => [
					'fulltext' => lang('Fulltext search'),
					'legacy' => lang('Legacy search, as used before'),
					'hybrid'  => lang('Hybrid search: RAG+Fulltext'),
					'rag' => lang('RAG search only'),
				],
				'help'    => '',
				'xmlrpc'  => false,
				'admin'   => false,
				'default' => 'fulltext',
			],
			'default_search_order' => [
				'type'    => 'select',
				'label'   => 'Should search in apps be ordered by relevance',
				'name'    => 'default_search_order',
				'values'  => [
					'app'  => lang('No, use search-order chosen in app'),
					'relevance' => lang('Always use relevance'),
				],
				'help'    => '',
				'xmlrpc'  => false,
				'admin'   => false,
				'default' => 'app',
			],
			'fulltext_match_wordstart' => [
				'type'    => 'select',
				'label'   => 'Change fulltext search pattern to match words starting with pattern',
				'name'    => 'fulltext_match_wordstart',
				'values'  => [
					'yes'  => lang('Yes').', '.lang('Default'),
					'no' => lang('No'),
				],
				'help'    => '',
				'xmlrpc'  => false,
				'admin'   => false,
				'default' => 'yes',
			],
		];
	}
	/**
	 * Add search to topmenu
	 *
	 * @param string|array $data hook-data
	 */
	public static function topMenuInfo($data)
	{
		/** @var \kdots_framework $framework */
		$framework = $GLOBALS['egw']->framework;
		if (!empty($GLOBALS['egw_info']['user']['apps']['rag']))
		{
			$framework->add_topmenu_item(
				'rag',
				Api\Egw::link('/index.php', ['menuaction' => 'rag.EGroupware\\Rag\\Ui.index', 'ajax' => 'true']),
				lang('Search'),
				'rag',
				'search',
			);
		}
	}
}