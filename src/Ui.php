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
class Ui
{
	const APP = 'rag';

	/**
	 * Methods callable via menuaction GET parameter
	 *
	 * @var array
	 */
	public $public_functions = [
		'index' => true,
		'edit'  => true,
	];

	/**
	 * Instance of our business object
	 *
	 * @var Embedding
	 */
	protected $embedding;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->embedding = new Embedding();
	}

	/**
	 * Fetch rows to display
	 *
	 * ACL is taken into account by calling Api\Link::title(s) on each entry and NOT returning it, if no title was returned!
	 *
	 * @param array $query
	 * @param ?array& $rows =null
	 * @param ?array& $readonlys =null
	 * @return int total number of entries found
	 */
	public function get_rows($query, ?array &$rows=null, ?array &$readonlys=null)
	{
		// store state in session
		Api\Cache::setSession(__CLASS__, 'state', [
			'search' => $query['search'],
			'col_filter' => $query['col_filter'],
		]);
		$rows = [];
		if (empty($query['search']))
		{
			return 0;
		}
		$apps_ids = [];
		switch($query['col_filter']['type'] ?? 'hybrid')
		{
			default:
			case 'hybrid':
				$search = 'search';
				break;
			case 'fulltext':
				$search = 'searchFulltext';
				break;
			case 'rag':
				$search = 'searchEmbeddings';
				break;
		}
		foreach($this->embedding->$search($query['search'], $query['col_filter']['apps']??'', $query['start']??0,
			// query twice as many entries requested, as user might not have access to all of them
			2*($query['num_rows']??50)) as $id => $dist_relevance)
		{
			if (!$id) continue; // $id===0 is used to signal nothing found, to not generate an SQL error

			if (is_numeric($id))
			{
				$app = current($query['col_filter']['apps']);
				$app_id = $id;
			}
			else
			{
				[$app, $app_id] = explode(':', $id, 2);
			}
			$rows[$id] = $dist_relevance;
			$apps_ids[$app][] = $app_id;
		}
		$total = $this->embedding->total ?? 0;
		foreach($apps_ids as $app => $ids)
		{
			foreach(Api\Link::titles($app, $ids) as $id => $title)
			{
				if (isset($rows[$row_id=$id]) || isset($rows[$row_id=$app.':'.$id]))
				{
					$rows[$row_id] = [
						'id' => $row_id,
						'app' => $app,
						'app_id' => $id,
						'title' => $title ?: '*** '.lang('Deleted').' ***',
						'dist_relevance' => $rows[$row_id],
					];
					unset($ids[array_search($id, $ids)]);
				}
			}
			// remove the entries not returned / user has no access to
			foreach($ids as $id)
			{
				if (isset($rows[$row_id=$id]) || isset($rows[$row_id=$app.':'.$id]))
				{
					unset($rows[$row_id]);
				}
			}
			// subtract number of entries user has no access to
			$total -= count($ids);
		}
		// return only wanted number of rows
		$rows = array_values(empty($query['num_rows']) ? $rows : array_slice($rows, $query['start']??0, $query['num_rows']));

		return $total;
	}

	/**
	 * Index
	 *
	 * @param ?array $content =null
	 */
	public function index(?array $content=null)
	{
		if (!is_array($content) || empty($content['nm']))
		{
			$content = [
				'nm' => (Api\Cache::getSession(__CLASS__, 'state')?:[]) + [
					'get_rows'       =>	self::APP.'.'.self::class.'.get_rows',
					'no_filter'      => true,	// disable the diverse filters we not (yet) use
					'no_filter2'     => true,
					'no_cat'         => true,
					'order'          =>	'dist_relevance',// IO name of the column to sort after (optional for the sortheaders)
					'sort'           =>	'DESC',// IO direction of the sort: 'ASC' or 'DESC'
					'row_id'         => 'id',
					'actions'        => $this->get_actions(),
					'default_cols'   => '!id,app_id',
					'col_filter'     => [
						'type' => $GLOBALS['egw_info']['user']['preferences']['rag']['searchType'] ?? 'hybrid',
						'apps' => $GLOBALS['egw_info']['user']['preferences']['rag']['searchType'] ?? [],
					],
				],
			];
		}
		$sel_options['apps'] = array_combine($apps = array_keys(Embedding::plugins()),
			array_map(static fn($app) => lang($app), $apps));

		$tmpl = new Api\Etemplate(self::APP.'.index');
		$tmpl->exec(self::APP.'.'.self::class.'.index', $content, $sel_options, [], ['nm' => $content['nm']]);
	}

	/**
	 * Return actions for search
	 *
	 * @return array
	 */
	protected function get_actions()
	{
		return [
			'view' => [
				'caption' => 'View',
				'default' => true,
				'allowOnMultiple' => false,
				'onExecute' => 'javaScript:app.rag.view',
				'group' => $group=0,
			],
		];
	}
}