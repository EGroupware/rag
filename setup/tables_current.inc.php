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

/**
 * ALTER TABLE egw_rag ADD COLUMN rag_embedding VECTOR(1024) NOT NULL;
 * CREATE VECTOR INDEX vec_index ON egw_rag (rag_embedding);
 */
 // INSERT INTO `egw_async` (`async_id`, `async_next`, `async_times`, `async_method`, `async_data`, `async_account_id`, `async_auto_id`) VALUES
 // ('rag:embed', 1763449800, '{\"min\":\"*\\/5\"}', 'EGroupware\\Rag\\Embedding::asyncJob', '', 5, 12410);

$phpgw_baseline = array(
	'egw_rag' => array(
		'fd' => array(
			'rag_id' => array('type' => 'auto','nullable' => False),
			'rag_app' => array('type' => 'ascii','precision' => '16','nullable' => False,'comment' => 'app-name'),
			'rag_app_id' => array('type' => 'int','precision' => '4','nullable' => False,'comment' => 'app-id'),
			'rag_chunk' => array('type' => 'int','precision' => '4','nullable' => False,'comment' => '0=title, 1,2... n-th chunk of description'),
			'rag_updated' => array('type' => 'timestamp','nullable' => False,'default' => 'current_timestamp'),
			'rag_embedding' => array('type' => 'vector','precision' => '1024','nullable' => False,'comment' => 'contains the embedding')
		),
		'pk' => array('rag_id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array(array('rag_app','rag_app_id','rag_chunk'))
	)
);