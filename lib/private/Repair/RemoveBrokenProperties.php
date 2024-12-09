<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OC\Repair;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class RemoveBrokenProperties implements IRepairStep {
	/**
	 * RemoveBrokenProperties constructor.
	 *
	 * @param IDBConnection $db
	 */
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @inheritdoc
	 */
	public function getName() {
		return 'Remove broken object properties';
	}

	/**
	 * @inheritdoc
	 */
	public function run(IOutput $output) {
		// select all object properties
		$cmd = $this->db->getQueryBuilder();
		$cmd->select('id', 'propertyvalue')
			->from('properties')
			->where($cmd->expr()->eq('valuetype', $cmd->createNamedParameter('3', IQueryBuilder::PARAM_INT), IQueryBuilder::PARAM_INT));
		$result = $cmd->executeQuery();
		// find broken object properties
		$brokenIds = [];
		while ($entry = $result->fetch()) {
			if (!empty($entry['propertyvalue'])) {
				$object = @unserialize(str_replace('\x00', chr(0), $entry['propertyvalue']));
				if ($object === false) {
					$brokenIds[] = $entry['id'];
				}
			} else {
				$brokenIds[] = $entry['id'];
			}
		}
		$result->closeCursor();
		// delete broken object properties
		$cmd = $this->db->getQueryBuilder();
		$cmd->delete('properties')
			->where($cmd->expr()->in('id', $cmd->createParameter('ids'), IQueryBuilder::PARAM_STR_ARRAY));
		foreach (array_chunk($brokenIds, 1000) as $chunkIds) {
			$cmd->setParameter('ids', $chunkIds, IQueryBuilder::PARAM_STR_ARRAY);
			$cmd->executeStatement();
		}
		$total = count($brokenIds);
		$output->info("$total broken object properties removed");
	}
}
