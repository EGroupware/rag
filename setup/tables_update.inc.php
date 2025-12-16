<?php
/**
 * EGroupware - Setup
 * https://www.egroupware.org
 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
 *
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package rag
 * @subpackage setup
 */

use EGroupware\Api;
use EGroupware\Rag;

/**
 * Create fulltext index table
 *
 * @return string
 */
function rag_upgrade0_1_001()
{
	$GLOBALS['egw_setup']->oProc->CreateTable('egw_rag_fulltext',array(
		'fd' => array(
			'ft_id' => array('type' => 'auto','nullable' => False),
			'ft_app' => array('type' => 'ascii','precision' => '16','nullable' => False),
			'ft_app_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'ft_updated' => array('type' => 'timestamp','nullable' => False,'default' => 'current_timestamp'),
			'ft_title' => array('type' => 'varchar','precision' => '255'),
			'ft_description' => array('type' => 'longtext'),
			'ft_extra' => array('type' => 'longtext','meta' => 'json','comment' => 'all other textfields of the app as one JSON array')
		),
		'pk' => array('ft_id'),
		'fk' => array(),
		'ix' => array('ft_app_id',array('ft_title','ft_description','ft_extra', 'options' => array('mysql' => 'FULLTEXT'))),
		'uc' => array(array('ft_app','ft_app_id'))
	));

	return $GLOBALS['setup_info']['rag']['currentver'] = '0.1.002';
}

/**
 * Set (rag|ft)_updated to the exact modification timestamp of the entry, not the creation time of the embedding or fulltext index
 *
 * @return string
 * @throws Api\Db\Exception
 * @throws Api\Db\Exception\InvalidSql
 */
function rag_upgrade0_1_002()
{
	/** @var Api\Db $db */
	$db = $GLOBALS['egw_setup']->db;
	foreach(Rag\Embedding::plugins() as $app => $class)
	{
		/** @var Rag\Embedding\Base $plugin */
		$plugin = new $class;
		$db->query('UPDATE egw_rag_fulltext' .
			' JOIN ' . $plugin->table(false) . ' ON ft_app_id=' . $plugin->id() .
			' SET ft_updated=' . $plugin->modified() .
			' WHERE ft_app=' . $db->quote($app), __LINE__, __FILE__);
		$db->query('UPDATE egw_rag' .
			' JOIN ' . $plugin->table(false) . ' ON rag_app_id=' . $plugin->id() .
			' SET rag_updated=' . $plugin->modified() .
			' WHERE rag_app=' . $db->quote($app), __LINE__, __FILE__);
	}
	return $GLOBALS['setup_info']['rag']['currentver'] = '0.1.003';
}