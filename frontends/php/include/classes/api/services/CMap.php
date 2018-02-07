<?php
/*
** Zabbix
** Copyright (C) 2001-2017 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * Class containing methods for operations with maps.
 */
class CMap extends CMapElement {

	protected $tableName = 'sysmaps';
	protected $tableAlias = 's';
	protected $sortColumns = ['name', 'width', 'height'];

	private $defOptions = [
		'sysmapids'					=> null,
		'userids'					=> null,
		'editable'					=> null,
		'nopermissions'				=> null,
		// filter
		'filter'					=> null,
		'search'					=> null,
		'searchByAny'				=> null,
		'startSearch'				=> false,
		'excludeSearch'				=> false,
		'searchWildcardsEnabled'	=> null,
		// output
		'output'					=> API_OUTPUT_EXTEND,
		'selectSelements'			=> null,
		'selectShapes'				=> null,
		'selectLines'				=> null,
		'selectLinks'				=> null,
		'selectIconMap'				=> null,
		'selectUrls'				=> null,
		'selectUsers'				=> null,
		'selectUserGroups'			=> null,
		'countOutput'				=> false,
		'expandUrls' 				=> null,
		'preservekeys'				=> false,
		'sortfield'					=> '',
		'sortorder'					=> '',
		'limit'						=> null
	];


	/**
	 * Get map data.
	 *
	 * @param array  $options
	 * @param array  $options['sysmapids']					Map IDs.
	 * @param bool	 $options['output']						List of map parameters to return.
	 * @param array  $options['selectSelements']			List of map element properties to return.
	 * @param array  $options['selectShapes']				List of map shape properties to return.
	 * @param array  $options['selectLines']				List of map line properties to return.
	 * @param array  $options['selectLinks']				List of map link properties to return.
	 * @param array  $options['selectIconMap']				List of map icon map properties to return.
	 * @param array  $options['selectUrls']					List of map URL properties to return.
	 * @param array  $options['selectUsers']				List of users that the map is shared with.
	 * @param array  $options['selectUserGroups']			List of user groups that the map is shared with.
	 * @param bool	 $options['countOutput']				Return the count of records, instead of actual results.
	 * @param array  $options['userids']					Map owner user IDs.
	 * @param bool   $options['editable']					Return with read-write permission only. Ignored for
	 *														SuperAdmins.
	 * @param bool	 $options['nopermissions']				Return requested maps even if user has no permissions to
	 *														them.
	 * @param array  $options['filter']						List of field and exactly matched value pairs by which maps
	 *														need to be filtered.
	 * @param array  $options['search']						List of field-value pairs by which maps need to be searched.
	 * @param array  $options['expandUrls']					Adds global map URLs to the corresponding map elements and
	 *														expands macros in all map element URLs.
	 * @param bool	 $options['searchByAny']
	 * @param bool	 $options['startSearch']
	 * @param bool	 $options['excludeSearch']
	 * @param bool	 $options['searchWildcardsEnabled']
	 * @param array  $options['preservekeys']				Use IDs as keys in the resulting array.
	 * @param int    $options['limit']						Limit selection.
	 * @param string $options['sortorder']
	 * @param string $options['sortfield']
	 *
	 * @return array|integer Requested map data as array or the count of retrieved objects, if the countOutput
	 *						 parameter has been used.
	 */
	public function get(array $options = []) {
		$user_data = self::$userData;

		$options = zbx_array_merge($this->defOptions, $options);

		$limit = $options['limit'];
		$options['limit'] = null;

		if ($options['countOutput']) {
			$count_output = true;
			$options['output'] = ['sysmapid'];
			$options['countOutput'] = false;
		}
		else {
			$count_output = false;
		}

		$result = $this->get_maps($options);

		if ($result && self::$userData['type'] != USER_TYPE_SUPER_ADMIN && !$options['nopermissions']) {
			$sysmapids = array_flip($this->checkPermissions(array_keys($result), (bool) $options['editable']));

			foreach ($result as $sysmapid => $foo) {
				if (!array_key_exists($sysmapid, $sysmapids)) {
					unset($result[$sysmapid]);
				}
			}
		}

		if ($count_output) {
			return count($result);
		}

		if ($limit !== null) {
			$result = array_slice($result, 0, $limit, true);
		}

		if ($result) {
			$result = $this->addRelatedObjects($options, $result);
		}

		// removing keys (hash -> array)
		if (!$options['preservekeys']) {
			$result = zbx_cleanHashes($result);
		}

		return $result;
	}

	/**
	 * Returns maps without checking permissions to the elements.
	 */
	private function get_maps(array $options) {
		$sql_parts = [
			'select'	=> ['sysmaps' => 's.sysmapid'],
			'from'		=> ['sysmaps' => 'sysmaps s'],
			'where'		=> [],
			'order'		=> [],
			'limit'		=> null
		];

		// Editable + permission check.
		if (self::$userData['type'] < USER_TYPE_ZABBIX_ADMIN && !$options['nopermissions']) {
			$public_maps = '';

			if ($options['editable']) {
				$permission = PERM_READ_WRITE;
			}
			else {
				$permission = PERM_READ;
				$public_maps = ' OR s.private='.PUBLIC_SHARING;
			}

			$user_groups = getUserGroupsByUserId(self::$userData['userid']);

			$sql_parts['where'][] = '(EXISTS ('.
					'SELECT NULL'.
					' FROM sysmap_user su'.
					' WHERE s.sysmapid=su.sysmapid'.
						' AND su.userid='.self::$userData['userid'].
						' AND su.permission>='.$permission.
				')'.
				' OR EXISTS ('.
					'SELECT NULL'.
					' FROM sysmap_usrgrp sg'.
					' WHERE s.sysmapid=sg.sysmapid'.
						' AND '.dbConditionInt('sg.usrgrpid', $user_groups).
						' AND sg.permission>='.$permission.
				')'.
				' OR s.userid='.self::$userData['userid'].
				$public_maps.
			')';
		}

		// sysmapids
		if ($options['sysmapids'] !== null) {
			zbx_value2array($options['sysmapids']);
			$sql_parts['where']['sysmapid'] = dbConditionInt('s.sysmapid', $options['sysmapids']);
		}

		// userids
		if ($options['userids'] !== null) {
			zbx_value2array($options['userids']);

			$sql_parts['where'][] = dbConditionInt('s.userid', $options['userids']);
		}

		// search
		if ($options['search'] !== null) {
			zbx_db_search('sysmaps s', $options, $sql_parts);
		}

		// filter
		if ($options['filter'] !== null) {
			$this->dbFilter('sysmaps s', $options, $sql_parts);
		}

		// limit
		if (zbx_ctype_digit($options['limit']) && $options['limit']) {
			$sql_parts['limit'] = $options['limit'];
		}

		$result = [];

		$sql_parts = $this->applyQueryOutputOptions($this->tableName(), $this->tableAlias(), $options, $sql_parts);
		$sql_parts = $this->applyQuerySortOptions($this->tableName(), $this->tableAlias(), $options, $sql_parts);
		$sysmaps = DBselect($this->createSelectQueryFromParts($sql_parts), $sql_parts['limit']);

		while ($sysmap = DBfetch($sysmaps)) {
			$result[$sysmap['sysmapid']] = $sysmap;
		}

		return $result;
	}

	/**
	 * Returns maps with selected permission level.
	 *
	 * @param array $sysmapids
	 * @param bool  $editable
	 *
	 * @return array
	 */
	private function checkPermissions(array $sysmapids, $editable) {
		$sysmaps = [];
		foreach ($sysmapids as $sysmapid) {
			$sysmaps[$sysmapid] = ['permission' => PERM_NONE];
		}

		$selement_maps = [];

		// Populating the map tree $selement_maps and list of shared maps $sysmaps.
		do {
			$selements = self::get_selements($sysmapids, SYSMAP_ELEMENT_TYPE_MAP);

			$sysmapids = [];

			foreach ($selements as $sysmapid => $selement) {
				if (!array_key_exists($sysmapid, $sysmaps)) {
					$sysmapids[$sysmapid] = true;
				}
			}

			$sysmapids = array_keys($sysmapids);
			$selement_maps += $selements;

			if ($sysmapids) {
				$db_sysmaps = $this->get_maps([
					'output' => [],
					'sysmapids' => $sysmapids,
					'preservekeys' => true
				] + $this->defOptions);

				foreach ($sysmapids as $i => $sysmapid) {
					if (array_key_exists($sysmapid, $db_sysmaps)) {
						$sysmaps[$sysmapid] = ['permission' => PERM_NONE];
					}
					else {
						unset($sysmapids[$i]);
					}
				}
			}
		}
		while ($sysmapids);

		if ($editable) {
			self::unsetMapsByTree($sysmaps, $selement_maps);
		}
		else {
			$selement_images = self::get_selements(array_keys($sysmaps), SYSMAP_ELEMENT_TYPE_IMAGE);

			self::setMapPermissions($sysmaps, $selement_images, [0 => []], $selement_maps);
		}
		$sysmapids = self::getSysmapIds($sysmaps, $editable);

		// Check permissions to the host groups.
		if ($sysmapids) {
			$selement_groups = self::get_selements($sysmapids, SYSMAP_ELEMENT_TYPE_HOST_GROUP);

			$db_groups = API::HostGroup()->get([
				'output' => [],
				'groupids' => array_keys($selement_groups),
				'preservekeys' => true
			]);

			if ($editable) {
				self::unsetMapsByElements($sysmaps, $selement_groups, $db_groups);
				self::unsetMapsByTree($sysmaps, $selement_maps);
			}
			else {
				self::setMapPermissions($sysmaps, $selement_groups, $db_groups, $selement_maps);
			}
			$sysmapids = self::getSysmapIds($sysmaps, $editable);
		}

		// Check permissions to the hosts.
		if ($sysmapids) {
			$selement_hosts = self::get_selements($sysmapids, SYSMAP_ELEMENT_TYPE_HOST);

			$db_hosts = API::Host()->get([
				'output' => [],
				'hostids' => array_keys($selement_hosts),
				'preservekeys' => true
			]);

			if ($editable) {
				self::unsetMapsByElements($sysmaps, $selement_hosts, $db_hosts);
				self::unsetMapsByTree($sysmaps, $selement_maps);
			}
			else {
				self::setMapPermissions($sysmaps, $selement_hosts, $db_hosts, $selement_maps);
			}
			$sysmapids = self::getSysmapIds($sysmaps, $editable);
		}

		// Check permissions to the triggers.
		if ($sysmapids) {
			$selement_triggers = self::get_selements($sysmapids, SYSMAP_ELEMENT_TYPE_TRIGGER);
			$links = $editable ? self::get_links(array_keys($sysmaps)) : [];

			$db_triggers = API::Trigger()->get([
				'output' => [],
				'triggerids' => array_keys($selement_triggers + $links),
				'preservekeys' => true
			]);

			if ($editable) {
				self::unsetMapsByElements($sysmaps, $selement_triggers, $db_triggers);
				self::unsetMapsByElements($sysmaps, $links, $db_triggers);
				self::unsetMapsByTree($sysmaps, $selement_maps);
			}
			else {
				self::setMapPermissions($sysmaps, $selement_triggers, $db_triggers, $selement_maps);
			}
		}

		if (!$editable) {
			foreach ($sysmaps as $sysmapid => $sysmap) {
				if ($sysmap['permission'] == PERM_NONE) {
					unset($sysmaps[$sysmapid]);
				}
			}
		}

		return array_keys($sysmaps);
	}

	/**
	 * Returns map elements for selected maps.
	 */
	private static function get_selements(array $sysmapids, $elementtype) {
		$selements = [];

		switch ($elementtype) {
			case SYSMAP_ELEMENT_TYPE_IMAGE:
				$sql = 'SELECT se.selementid,se.sysmapid,0 AS elementid'.
					' FROM sysmaps_elements se'.
					' WHERE '.dbConditionInt('se.sysmapid', $sysmapids).
						' AND '.dbConditionInt('se.elementtype', [$elementtype]);
				break;

			case SYSMAP_ELEMENT_TYPE_HOST_GROUP:
			case SYSMAP_ELEMENT_TYPE_HOST:
			case SYSMAP_ELEMENT_TYPE_MAP:
				$sql = 'SELECT se.selementid,se.sysmapid,se.elementid'.
					' FROM sysmaps_elements se'.
					' WHERE '.dbConditionInt('se.sysmapid', $sysmapids).
						' AND '.dbConditionInt('se.elementtype', [$elementtype]);
				break;

			case SYSMAP_ELEMENT_TYPE_TRIGGER:
				$sql = 'SELECT se.selementid,se.sysmapid,st.triggerid AS elementid'.
					' FROM sysmaps_elements se,sysmap_element_trigger st'.
					' WHERE se.selementid=st.selementid'.
						' AND '.dbConditionInt('se.sysmapid', $sysmapids).
						' AND '.dbConditionInt('se.elementtype', [$elementtype]);
				break;
		}
		$db_selements = DBSelect($sql);

		while ($db_selement = DBfetch($db_selements)) {
			$selements[$db_selement['elementid']][] = [
				'sysmapid' => $db_selement['sysmapid'],
				'selementid' => $db_selement['selementid']
			];;
		}

		return $selements;
	}

	/**
	 * Returns map links for selected maps.
	 */
	private static function get_links(array $sysmapids) {
		$links = [];

		$db_links = DBSelect(
			'SELECT sl.linkid,sl.sysmapid,slt.triggerid'.
			' FROM sysmaps_links sl,sysmaps_link_triggers slt'.
			' WHERE sl.linkid=slt.linkid'.
				' AND '.dbConditionInt('sl.sysmapid', $sysmapids)
		);

		while ($db_link = DBfetch($db_links)) {
			$links[$db_link['triggerid']][] = [
				'sysmapid' => $db_link['sysmapid'],
				'linkid' => $db_link['linkid']
			];;
		}

		return $links;
	}

	/**
	 * Auxiliary function for unsetMapsByTree(). Removes branch of maps with inaccessible submaps.
	 */
	private static function unsetParentMap(array &$sysmaps, array $selement_maps, $sysmapid) {
		foreach ($selement_maps[$sysmapid] as $selement) {
			if (array_key_exists($selement['sysmapid'], $selement_maps)) {
				self::unsetParentMap($sysmaps, $selement_maps, $selement['sysmapid']);
			}
			unset($sysmaps[$selement['sysmapid']]);
		}
	}

	/**
	 * Removes all inaccessible maps by map tree.
	 *
	 * @param array $sysmaps[<sysmapids>]                     The list of accessible sysmaps.
	 * @param array $selement_maps                            The map tree.
	 * @param array $selement_maps[<sysmapid>][]['sysmapid']  Parent map ID.
	 */
	private static function unsetMapsByTree(array &$sysmaps, array $selement_maps) {
		foreach (array_keys($selement_maps) as $sysmapid) {
			if (!array_key_exists($sysmapid, $sysmaps)) {
				self::unsetParentMap($sysmaps, $selement_maps, $sysmapid);
			}
		}
	}

	/**
	 * Removes all inaccessible maps by inacessible elements.
	 *
	 * @param array $sysmaps[<sysmapids>]                 The list of accessible sysmaps.
	 * @param array $elements                             The map elements.
	 * @param array $elements[<elementid>][]['sysmapid']  Map ID.
	 * @param array $db_elements                          The list of accessible elements.
	 * @param array $db_elements[<elementid>]
	 */
	private static function unsetMapsByElements(array &$sysmaps, array $elements, array $db_elements) {
		foreach ($elements as $elementid => $selements) {
			if (!array_key_exists($elementid, $db_elements)) {
				foreach ($selements as $selement) {
					unset($sysmaps[$selement['sysmapid']]);
				}
			}
		}
	}

	/**
	 * Auxiliary function for setMapPermissions(). Set PERM_READ permission for all parent maps.
	 */
	private static function setParentMapPermissions(array &$sysmaps, array $selement_maps, $sysmapid) {
		if (array_key_exists($sysmapid, $selement_maps)) {
			foreach ($selement_maps[$sysmapid] as $selement) {
				self::setParentMapPermissions($sysmaps, $selement_maps, $selement['sysmapid']);
				$sysmaps[$selement['sysmapid']]['permission'] = PERM_READ;
			}
		}
	}

	/**
	 * Seting PERM_READ permissions for maps with at least one available element.
	 *
	 * @param array $sysmaps[<sysmapids>]                    The list of accessible sysmaps.
	 * @param array $elements                                The map elements.
	 * @param array $elements[<elementid>][]['sysmapid']     Map ID.
	 * @param array $db_elements                             The list of accessible elements.
	 * @param array $db_elements[<elementid>]
	 * @param array $selement_maps                            The map elements.
	 * @param array $selement_maps[<sysmapid>][]['sysmapid']  Map ID.
	 */
	private static function setMapPermissions(array &$sysmaps, array $elements, array $db_elements,
			array $selement_maps) {
		foreach ($elements as $elementid => $selements) {
			if (array_key_exists($elementid, $db_elements)) {
				foreach ($selements as $selement) {
					self::setParentMapPermissions($sysmaps, $selement_maps, $selement['sysmapid']);
					$sysmaps[$selement['sysmapid']]['permission'] = PERM_READ;
				}
			}
		}
	}

	/**
	 * Returns map ids which will be checked for permissions.
	 *
	 * @param array $sysmaps
	 * @param array $sysmaps[<sysmapid>]['permission']
	 * @param bool  $editable
	 */
	private static function getSysmapIds(array $sysmaps, $editable) {
		if ($editable) {
			return array_keys($sysmaps);
		}

		$sysmapids = [];

		foreach ($sysmaps as $sysmapid => $sysmap) {
			if ($sysmap['permission'] == PERM_NONE) {
				$sysmapids[] = $sysmapid;
			}
		}

		return $sysmapids;
	}

	/**
	 * Validates the input parameters for the delete() method.
	 *
	 * @param array $sysmapids
	 *
	 * @throws APIException if the input is invalid.
	 */
	protected function validateDelete(array $sysmapids) {
		if (!$sysmapids) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty input parameter.'));
		}

		$db_maps = $this->get([
			'output' => ['sysmapid'],
			'sysmapids' => $sysmapids,
			'editable' => true,
			'preservekeys' => true
		]);

		foreach ($sysmapids as $sysmapid) {
			if (!array_key_exists($sysmapid, $db_maps)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('No permissions to referred object or it does not exist!'));
			}
		}
	}

	/**
	 * Validate the input parameters for the create() method.
	 *
	 * @param array $maps		maps data array
	 *
	 * @throws APIException if the input is invalid.
	 */
	protected function validateCreate(array $maps) {
		if (!$maps) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty input parameter.'));
		}

		$user_data = self::$userData;

		$map_db_fields = [
			'name' => null,
			'width' => null,
			'height' => null,
			'urls' => [],
			'selements' => [],
			'links' => []
		];

		// Validate mandatory fields and map name.
		foreach ($maps as $map) {
			if (!check_db_fields($map_db_fields, $map)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect fields for sysmap.'));
			}
		}

		// Check for duplicate names.
		$duplicate = CArrayHelper::findDuplicate($maps, 'name');
		if ($duplicate) {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('Duplicate "name" value "%1$s" for map.', $duplicate['name'])
			);
		}

		// Check if map already exists.
		$db_maps = $this->get([
			'output' => ['name'],
			'filter' => ['name' => zbx_objectValues($maps, 'name')],
			'nopermissions' => true,
			'limit' => 1
		]);

		if ($db_maps) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _s('Map "%1$s" already exists.', $db_maps[0]['name']));
		}

		$private_validator = new CLimitedSetValidator([
			'values' => [PUBLIC_SHARING, PRIVATE_SHARING]
		]);

		$permission_validator = new CLimitedSetValidator([
			'values' => [PERM_READ, PERM_READ_WRITE]
		]);

		$expandproblem_types = [SYSMAP_PROBLEMS_NUMBER, SYSMAP_SINGLE_PROBLEM, SYSMAP_PROBLEMS_NUMBER_CRITICAL];
		$expandproblem_validator = new CLimitedSetValidator(['values' => $expandproblem_types]);

		// Continue to check 2 more mandatory fields and other optional fields.
		foreach ($maps as $map) {
			// Check mandatory fields "width" and "height".
			if ($map['width'] > 65535 || $map['width'] < 1) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Incorrect "width" value for map "%1$s".', $map['name'])
				);
			}

			if ($map['height'] > 65535 || $map['height'] < 1) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Incorrect "height" value for map "%1$s".', $map['name'])
				);
			}

			// Check if owner can be set.
			if (array_key_exists('userid', $map)) {
				if ($map['userid'] === '' || $map['userid'] === null || $map['userid'] === false) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Map owner cannot be empty.'));
				}
				elseif ($map['userid'] != $user_data['userid'] && $user_data['type'] != USER_TYPE_SUPER_ADMIN
						&& $user_data['type'] != USER_TYPE_ZABBIX_ADMIN) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Only administrators can set map owner.'));
				}
			}

			// Check for invalid "private" values.
			if (array_key_exists('private', $map)) {
				if (!$private_validator->validate($map['private'])) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Incorrect "private" value "%1$s" for map "%2$s".', $map['private'], $map['name'])
					);
				}
			}

			if (array_key_exists('expandproblem', $map) && !$expandproblem_validator->validate($map['expandproblem'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Incorrect value for field "%1$s": %2$s.', 'expandproblem',
					_s('value must be one of %1$s', implode(', ', $expandproblem_types))
				));
			}

			$userids = [];

			// Map user shares.
			if (array_key_exists('users', $map)) {
				if (!is_array($map['users'])) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
				}

				$required_fields = ['userid', 'permission'];

				foreach ($map['users'] as $share) {
					// Check required parameters.
					$missing_keys = array_diff($required_fields, array_keys($share));

					if ($missing_keys) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'User sharing is missing parameters: %1$s for map "%2$s".',
							implode(', ', $missing_keys),
							$map['name']
						));
					}
					else {
						foreach ($required_fields as $field) {
							if ($share[$field] === '' || $share[$field] === null) {
								self::exception(ZBX_API_ERROR_PARAMETERS, _s(
									'Sharing option "%1$s" is missing a value for map "%2$s".',
									$field,
									$map['name']
								));
							}
						}
					}

					if (!$permission_validator->validate($share['permission'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Incorrect "permission" value "%1$s" in users for map "%2$s".',
							$share['permission'],
							$map['name']
						));
					}

					if (array_key_exists('private', $map) && $map['private'] == PUBLIC_SHARING
							&& $share['permission'] == PERM_READ) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Map "%1$s" is public and read-only sharing is disallowed.', $map['name'])
						);
					}

					if (array_key_exists($share['userid'], $userids)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Duplicate userid "%1$s" in users for map "%2$s".', $share['userid'], $map['name'])
						);
					}

					$userids[$share['userid']] = $share['userid'];
				}
			}

			if (array_key_exists('userid', $map) && $map['userid']) {
				$userids[$map['userid']] = $map['userid'];
			}

			// Users validation.
			if ($userids) {
				$db_users = API::User()->get([
					'userids' => $userids,
					'countOutput' => true
				]);

				if (count($userids) != $db_users) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Incorrect user ID specified for map "%1$s".', $map['name'])
					);
				}
			}

			// Map user group shares.
			if (array_key_exists('userGroups', $map)) {
				if (!is_array($map['userGroups'])) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
				}

				$shared_user_groupids = [];
				$required_fields = ['usrgrpid', 'permission'];

				foreach ($map['userGroups'] as $share) {
					// Check required parameters.
					$missing_keys = array_diff($required_fields, array_keys($share));

					if ($missing_keys) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'User group sharing is missing parameters: %1$s for map "%2$s".',
							implode(', ', $missing_keys),
							$map['name']
						));
					}
					else {
						foreach ($required_fields as $field) {
							if ($share[$field] === '' || $share[$field] === null) {
								self::exception(ZBX_API_ERROR_PARAMETERS, _s(
									'Field "%1$s" is missing a value for map "%2$s".',
									$field,
									$map['name']
								));
							}
						}
					}

					if (!$permission_validator->validate($share['permission'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Incorrect "permission" value "%1$s" in user groups for map "%2$s".',
							$share['permission'],
							$map['name']
						));
					}

					if (array_key_exists('private', $map) && $map['private'] == PUBLIC_SHARING
							&& $share['permission'] == PERM_READ) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Map "%1$s" is public and read-only sharing is disallowed.', $map['name'])
						);
					}

					if (array_key_exists($share['usrgrpid'], $shared_user_groupids)) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Duplicate usrgrpid "%1$s" in user groups for map "%2$s".',
							$share['usrgrpid'],
							$map['name']
						));
					}

					$shared_user_groupids[$share['usrgrpid']] = $share['usrgrpid'];
				}

				if ($shared_user_groupids) {
					$db_user_groups = API::UserGroup()->get([
						'usrgrpids' => $shared_user_groupids,
						'countOutput' => true
					]);

					if (count($shared_user_groupids) != $db_user_groups) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Incorrect user group ID specified for map "%1$s".', $map['name'])
						);
					}
				}

				unset($shared_user_groupids);
			}

			// Map labels.
			$map_labels = ['label_type' => ['typeName' => _('icon')]];

			if (array_key_exists('label_format', $map) && $map['label_format'] == SYSMAP_LABEL_ADVANCED_ON) {
				$map_labels['label_type_hostgroup'] = [
					'string' => 'label_string_hostgroup',
					'typeName' => _('host group')
				];
				$map_labels['label_type_host'] = [
					'string' => 'label_string_host',
					'typeName' => _('host')
				];
				$map_labels['label_type_trigger'] = [
					'string' => 'label_string_trigger',
					'typeName' => _('trigger')
				];
				$map_labels['label_type_map'] = [
					'string' => 'label_string_map',
					'typeName' => _('map')
				];
				$map_labels['label_type_image'] = [
					'string' => 'label_string_image',
					'typeName' => _('image')
				];
			}

			foreach ($map_labels as $label_name => $label_data) {
				if (!array_key_exists($label_name, $map)) {
					continue;
				}

				if (sysmapElementLabel($map[$label_name]) === false) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Incorrect %1$s label type value for map "%2$s".', $label_data['typeName'], $map['name'])
					);
				}

				if ($map[$label_name] == MAP_LABEL_TYPE_CUSTOM) {
					if (!array_key_exists('string', $label_data)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Incorrect %1$s label type value for map "%2$s".', $label_data['typeName'], $map['name'])
						);
					}

					if (!array_key_exists($label_data['string'], $map) || zbx_empty($map[$label_data['string']])) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
								'Custom label for map "%2$s" elements of type "%1$s" may not be empty.',
								$label_data['typeName'],
								$map['name']
							)
						);
					}
				}

				if ($label_name == 'label_type_image' && $map[$label_name] == MAP_LABEL_TYPE_STATUS) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Incorrect %1$s label type value for map "%2$s".', $label_data['typeName'], $map['name'])
					);
				}

				if ($label_name === 'label_type' || $label_name === 'label_type_host') {
					continue;
				}

				if ($map[$label_name] == MAP_LABEL_TYPE_IP) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Incorrect %1$s label type value for map "%2$s".', $label_data['typeName'], $map['name'])
					);
				}
			}

			// Validating grid options.
			$possibleGridSizes = [20, 40, 50, 75, 100];

			// Grid size.
			if (array_key_exists('grid_size', $map) && !in_array($map['grid_size'], $possibleGridSizes)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s(
					'Value "%1$s" is invalid for parameter "grid_show". Choices are: "%2$s".',
					$map['grid_size'],
					implode('", "', $possibleGridSizes)
				));
			}

			// Grid auto align.
			if (array_key_exists('grid_align', $map) && $map['grid_align'] != SYSMAP_GRID_ALIGN_ON
					&& $map['grid_align'] != SYSMAP_GRID_ALIGN_OFF) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s(
					'Value "%1$s" is invalid for parameter "grid_align". Choices are: "%2$s" and "%3$s"',
					$map['grid_align'],
					SYSMAP_GRID_ALIGN_ON,
					SYSMAP_GRID_ALIGN_OFF
				));
			}

			// Grid show.
			if (array_key_exists('grid_show', $map) && $map['grid_show'] != SYSMAP_GRID_SHOW_ON
					&& $map['grid_show'] != SYSMAP_GRID_SHOW_OFF) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s(
					'Value "%1$s" is invalid for parameter "grid_show". Choices are: "%2$s" and "%3$s".',
					$map['grid_show'],
					SYSMAP_GRID_SHOW_ON,
					SYSMAP_GRID_SHOW_OFF
				));
			}

			// Urls.
			if (array_key_exists('urls', $map) && $map['urls']) {
				$url_names = zbx_toHash($map['urls'], 'name');

				foreach ($map['urls'] as $url) {
					if ($url['name'] === '' || $url['url'] === '') {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('URL should have both "name" and "url" fields for map "%1$s".', $map['name'])
						);
					}

					if (!array_key_exists($url['name'], $url_names)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('URL name should be unique for map "%1$s".', $map['name'])
						);
					}

					if (!CHtmlUrlValidator::validate($url['url'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('Wrong value for url field.'));
					}

					unset($url_names[$url['name']]);
				}
			}

			if (array_key_exists('selements', $map) && !is_array($map['selements'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
			}

			// Map selement links.
			if (array_key_exists('links', $map) && $map['links']) {
				$selementids = zbx_objectValues($map['selements'], 'selementid');

				foreach ($map['links'] as $link) {
					if (!in_array($link['selementid1'], $selementids)) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Link selementid1 field is pointing to a nonexistent map selement ID "%1$s" for map "%2$s".',
							$link['selementid1'],
							$map['name']
						));
					}

					if (!in_array($link['selementid2'], $selementids)) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Link selementid2 field is pointing to a nonexistent map selement ID "%1$s" for map "%2$s".',
							$link['selementid2'],
							$map['name']
						));
					}
				}
			}
		}
	}

	/**
	 * Validate the input parameters for the update() method.
	 *
	 * @param array $maps			maps data array
	 * @param array $db_maps		db maps data array
	 *
	 * @throws APIException if the input is invalid.
	 */
	protected function validateUpdate(array $maps, array $db_maps) {
		if (!$maps) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty input parameter.'));
		}

		$user_data = self::$userData;

		// Validate given IDs.
		$this->checkObjectIds($maps, 'sysmapid',
			_('No "%1$s" given for map.'),
			_('Empty map ID.'),
			_('Incorrect map ID.')
		);

		$check_names = [];

		foreach ($maps as $map) {
			// Check if this map exists and user has write permissions.
			if (!array_key_exists($map['sysmapid'], $db_maps)) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_('No permissions to referred object or it does not exist!')
				);
			}

			// Validate "name" field.
			if (array_key_exists('name', $map)) {
				if (is_array($map['name'])) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
				}
				elseif ($map['name'] === '' || $map['name'] === null || $map['name'] === false) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Map name cannot be empty.'));
				}

				if ($db_maps[$map['sysmapid']]['name'] !== $map['name']) {
					$check_names[] = $map;
				}
			}
		}

		if ($check_names) {
			// Check for duplicate names.
			$duplicate = CArrayHelper::findDuplicate($check_names, 'name');
			if ($duplicate) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Duplicate "name" value "%1$s" for map.', $duplicate['name'])
				);
			}

			$db_map_names = $this->get([
				'output' => ['sysmapid', 'name'],
				'filter' => ['name' => zbx_objectValues($check_names, 'name')],
				'nopermissions' => true
			]);
			$db_map_names = zbx_toHash($db_map_names, 'name');

			// Check for existing names.
			foreach ($check_names as $map) {
				if (array_key_exists($map['name'], $db_map_names)
						&& bccomp($db_map_names[$map['name']]['sysmapid'], $map['sysmapid']) != 0) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Map "%1$s" already exists.', $map['name'])
					);
				}
			}
		}

		$private_validator = new CLimitedSetValidator([
			'values' => [PUBLIC_SHARING, PRIVATE_SHARING]
		]);

		$permission_validator = new CLimitedSetValidator([
			'values' => [PERM_READ, PERM_READ_WRITE]
		]);

		$expandproblem_types = [SYSMAP_PROBLEMS_NUMBER, SYSMAP_SINGLE_PROBLEM, SYSMAP_PROBLEMS_NUMBER_CRITICAL];
		$expandproblem_validator = new CLimitedSetValidator(['values' => $expandproblem_types]);

		foreach ($maps as $map) {
			// Check if owner can be set.
			if (array_key_exists('userid', $map)) {
				if ($map['userid'] === '' || $map['userid'] === null || $map['userid'] === false) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Map owner cannot be empty.'));
				}
				elseif ($map['userid'] != $user_data['userid'] && $user_data['type'] != USER_TYPE_SUPER_ADMIN
						&& $user_data['type'] != USER_TYPE_ZABBIX_ADMIN) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Only administrators can set map owner.'));
				}
			}

			// Unset extra field.
			unset($db_maps[$map['sysmapid']]['userid']);

			$map = array_merge($db_maps[$map['sysmapid']], $map);

			// Check "width" and "height" fields.
			if ($map['width'] > 65535 || $map['width'] < 1) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Incorrect "width" value for map "%1$s".', $map['name'])
				);
			}

			if ($map['height'] > 65535 || $map['height'] < 1) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Incorrect "height" value for map "%1$s".', $map['name'])
				);
			}

			if (!$private_validator->validate($map['private'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Incorrect "private" value "%1$s" for map "%2$s".', $map['private'], $map['name'])
				);
			}

			if (array_key_exists('expandproblem', $map) && !$expandproblem_validator->validate($map['expandproblem'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Incorrect value for field "%1$s": %2$s.', 'expandproblem',
					_s('value must be one of %1$s', implode(', ', $expandproblem_types))
				));
			}

			$userids = [];

			// Map user shares.
			if (array_key_exists('users', $map)) {
				if (!is_array($map['users'])) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
				}

				$required_fields = ['userid', 'permission'];

				foreach ($map['users'] as $share) {
					// Check required parameters.
					$missing_keys = array_diff($required_fields, array_keys($share));

					if ($missing_keys) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'User sharing is missing parameters: %1$s for map "%2$s".',
							implode(', ', $missing_keys),
							$map['name']
						));
					}
					else {
						foreach ($required_fields as $field) {
							if ($share[$field] === '' || $share[$field] === null) {
								self::exception(ZBX_API_ERROR_PARAMETERS, _s(
									'Sharing option "%1$s" is missing a value for map "%2$s".',
									$field,
									$map['name']
								));
							}
						}
					}

					if (!$permission_validator->validate($share['permission'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Incorrect "permission" value "%1$s" in users for map "%2$s".',
							$share['permission'],
							$map['name']
						));
					}

					if ($map['private'] == PUBLIC_SHARING && $share['permission'] == PERM_READ) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Map "%1$s" is public and read-only sharing is disallowed.', $map['name'])
						);
					}

					if (array_key_exists($share['userid'], $userids)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Duplicate userid "%1$s" in users for map "%2$s".', $share['userid'], $map['name'])
						);
					}

					$userids[$share['userid']] = $share['userid'];
				}
			}

			if (array_key_exists('userid', $map) && $map['userid']) {
				$userids[$map['userid']] = $map['userid'];
			}

			// Users validation.
			if ($userids) {
				$db_users = API::User()->get([
					'userids' => $userids,
					'countOutput' => true
				]);

				if (count($userids) != $db_users) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Incorrect user ID specified for map "%1$s".', $map['name'])
					);
				}
			}

			// Map user group shares.
			if (array_key_exists('userGroups', $map)) {
				if (!is_array($map['userGroups'])) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
				}

				$shared_user_groupids = [];
				$required_fields = ['usrgrpid', 'permission'];

				foreach ($map['userGroups'] as $share) {
					// Check required parameters.
					$missing_keys = array_diff($required_fields, array_keys($share));

					if ($missing_keys) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'User group sharing is missing parameters: %1$s for map "%2$s".',
							implode(', ', $missing_keys),
							$map['name'])
						);
					}
					else {
						foreach ($required_fields as $field) {
							if ($share[$field] === '' || $share[$field] === null) {
								self::exception(ZBX_API_ERROR_PARAMETERS, _s(
									'Sharing option "%1$s" is missing a value for map "%2$s".',
									$field,
									$map['name']
								));
							}
						}
					}

					if (!$permission_validator->validate($share['permission'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Incorrect "permission" value "%1$s" in user groups for map "%2$s".',
							$share['permission'],
							$map['name']
						));
					}

					if ($map['private'] == PUBLIC_SHARING && $share['permission'] == PERM_READ) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Map "%1$s" is public and read-only sharing is disallowed.', $map['name'])
						);
					}

					if (array_key_exists($share['usrgrpid'], $shared_user_groupids)) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Duplicate usrgrpid "%1$s" in user groups for map "%2$s".',
							$share['usrgrpid'],
							$map['name']
						));
					}

					$shared_user_groupids[$share['usrgrpid']] = $share['usrgrpid'];
				}

				if ($shared_user_groupids) {
					$db_user_groups = API::UserGroup()->get([
						'usrgrpids' => $shared_user_groupids,
						'countOutput' => true
					]);

					if (count($shared_user_groupids) != $db_user_groups) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Incorrect user group ID specified for map "%1$s".', $map['name'])
						);
					}
				}

				unset($shared_user_groupids);
			}

			// Map labels.
			$map_labels = ['label_type' => ['typeName' => _('icon')]];

			if (array_key_exists('label_format', $map)
					&& $map['label_format'] == SYSMAP_LABEL_ADVANCED_ON) {
				$map_labels['label_type_hostgroup'] = [
					'string' => 'label_string_hostgroup',
					'typeName' => _('host group')
				];
				$map_labels['label_type_host'] = [
					'string' => 'label_string_host',
					'typeName' => _('host')
				];
				$map_labels['label_type_trigger'] = [
					'string' => 'label_string_trigger',
					'typeName' => _('trigger')
				];
				$map_labels['label_type_map'] = [
					'string' => 'label_string_map',
					'typeName' => _('map')
				];
				$map_labels['label_type_image'] = [
					'string' => 'label_string_image',
					'typeName' => _('image')
				];
			}

			foreach ($map_labels as $label_name => $labelData) {
				if (!array_key_exists($label_name, $map)) {
					continue;
				}

				if (sysmapElementLabel($map[$label_name]) === false) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _s(
						'Incorrect %1$s label type value for map "%2$s".',
						$labelData['typeName'],
						$map['name']
					));
				}

				if ($map[$label_name] == MAP_LABEL_TYPE_CUSTOM) {
					if (!array_key_exists('string', $labelData)) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Incorrect %1$s label type value for map "%2$s".',
							$labelData['typeName'],
							$map['name']
						));
					}

					if (!array_key_exists($labelData['string'], $map) || zbx_empty($map[$labelData['string']])) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Custom label for map "%2$s" elements of type "%1$s" may not be empty.',
							$labelData['typeName'],
							$map['name']
						));
					}
				}

				if ($label_name === 'label_type_image' && $map[$label_name] == MAP_LABEL_TYPE_STATUS) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _s(
						'Incorrect %1$s label type value for map "%2$s".',
						$labelData['typeName'],
						$map['name']
					));
				}

				if ($label_name === 'label_type' || $label_name === 'label_type_host') {
					continue;
				}

				if ($map[$label_name] == MAP_LABEL_TYPE_IP) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _s(
						'Incorrect %1$s label type value for map "%2$s".',
						$labelData['typeName'],
						$map['name']
					));
				}
			}

			// Validating grid options.
			$possibleGridSizes = [20, 40, 50, 75, 100];

			// Grid size.
			if (array_key_exists('grid_size', $map) && !in_array($map['grid_size'], $possibleGridSizes)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s(
					'Value "%1$s" is invalid for parameter "grid_show". Choices are: "%2$s".',
					$map['grid_size'],
					implode('", "', $possibleGridSizes)
				));
			}

			// Grid auto align.
			if (array_key_exists('grid_align', $map) && $map['grid_align'] != SYSMAP_GRID_ALIGN_ON
					&& $map['grid_align'] != SYSMAP_GRID_ALIGN_OFF) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s(
					'Value "%1$s" is invalid for parameter "grid_align". Choices are: "%2$s" and "%3$s"',
					$map['grid_align'],
					SYSMAP_GRID_ALIGN_ON,
					SYSMAP_GRID_ALIGN_OFF
				));
			}

			// Grid show.
			if (array_key_exists('grid_show', $map) && $map['grid_show'] != SYSMAP_GRID_SHOW_ON
					&& $map['grid_show'] != SYSMAP_GRID_SHOW_OFF) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s(
					'Value "%1$s" is invalid for parameter "grid_show". Choices are: "%2$s" and "%3$s".',
					$map['grid_show'],
					SYSMAP_GRID_SHOW_ON,
					SYSMAP_GRID_SHOW_OFF
				));
			}

			// Urls.
			if (array_key_exists('urls', $map) && !empty($map['urls'])) {
				$urlNames = zbx_toHash($map['urls'], 'name');

				foreach ($map['urls'] as $url) {
					if ($url['name'] === '' || $url['url'] === '') {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('URL should have both "name" and "url" fields for map "%1$s".', $map['name'])
						);
					}

					if (!array_key_exists($url['name'], $urlNames)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('URL name should be unique for map "%1$s".', $map['name'])
						);
					}

					if (!CHtmlUrlValidator::validate($url['url'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _('Wrong value for url field.'));
					}

					unset($urlNames[$url['name']]);
				}
			}

			if (array_key_exists('selements', $map) && !is_array($map['selements'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
			}

			// Map selement links.
			if (array_key_exists('links', $map) && $map['links']) {
				$selementids = zbx_objectValues($map['selements'], 'selementid');

				foreach ($map['links'] as $link) {
					if (!in_array($link['selementid1'], $selementids)) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Link selementid1 field is pointing to a nonexistent map selement ID "%1$s" for map "%2$s".',
							$link['selementid1'],
							$map['name']
						));
					}

					if (!in_array($link['selementid2'], $selementids)) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s(
							'Link selementid2 field is pointing to a nonexistent map selement ID "%1$s" for map "%2$s".',
							$link['selementid2'],
							$map['name']
						));
					}
				}
			}
		}
	}

	/**
	 * Add map.
	 *
	 * @param array  $maps
	 * @param string $maps['name']
	 * @param array  $maps['width']
	 * @param int    $maps['height']
	 * @param string $maps['backgroundid']
	 * @param string $maps['highlight']
	 * @param array  $maps['label_type']
	 * @param int    $maps['label_location']
	 * @param int    $maps['grid_size']			size of one grid cell. 100 refers to 100x100 and so on.
	 * @param int    $maps['grid_show']			does grid need to be shown. Constants: SYSMAP_GRID_SHOW_ON / SYSMAP_GRID_SHOW_OFF
	 * @param int    $maps['grid_align']		do elements need to be aligned to the grid. Constants: SYSMAP_GRID_ALIGN_ON / SYSMAP_GRID_ALIGN_OFF
	 *
	 * @return array
	 */
	public function create($maps) {
		$maps = zbx_toArray($maps);

		$this->validateCreate($maps);

		foreach ($maps as &$map) {
			$map['userid'] = array_key_exists('userid', $map) ? $map['userid'] : self::$userData['userid'];
		}
		unset($map);

		$sysmapids = DB::insert('sysmaps', $maps);

		$shared_users = [];
		$shared_user_groups = [];
		$urls = [];
		$shapes = [];
		$selements = [];
		$links = [];
		$api_shape_rules = ['type' => API_OBJECTS, 'flags' => API_NORMALIZE, 'fields' => [
			'type' =>				['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => implode(',', [SYSMAP_SHAPE_TYPE_RECTANGLE, SYSMAP_SHAPE_TYPE_ELLIPSE])],
			'x' =>					['type' => API_INT32],
			'y' =>					['type' => API_INT32],
			'width' =>				['type' => API_INT32],
			'height' =>				['type' => API_INT32],
			'font' =>				['type' => API_INT32, 'in' => '0:12'],
			'font_size' =>			['type' => API_INT32, 'in' => '1:250'],
			'text_halign' =>		['type' => API_INT32, 'in' => implode(',', [SYSMAP_SHAPE_LABEL_HALIGN_CENTER, SYSMAP_SHAPE_LABEL_HALIGN_LEFT, SYSMAP_SHAPE_LABEL_HALIGN_RIGHT])],
			'text_valign' =>		['type' => API_INT32, 'in' => implode(',', [SYSMAP_SHAPE_LABEL_VALIGN_MIDDLE, SYSMAP_SHAPE_LABEL_VALIGN_TOP, SYSMAP_SHAPE_LABEL_VALIGN_BOTTOM])],
			'border_type' =>		['type' => API_INT32, 'in' => implode(',', [SYSMAP_SHAPE_BORDER_TYPE_NONE, SYSMAP_SHAPE_BORDER_TYPE_SOLID, SYSMAP_SHAPE_BORDER_TYPE_DOTTED, SYSMAP_SHAPE_BORDER_TYPE_DASHED])],
			'border_width' =>		['type' => API_INT32, 'in' => '0:50'],
			'border_color' =>		['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('sysmap_shape', 'border_color')],
			'background_color' =>	['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('sysmap_shape', 'background_color')],
			'font_color' =>			['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('sysmap_shape', 'font_color')],
			'text' =>				['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('sysmap_shape', 'text')],
			'zindex' =>				['type' => API_INT32]
		]];
		$api_line_rules = ['type' => API_OBJECTS, 'flags' => API_NORMALIZE, 'fields' => [
			'x1' =>					['type' => API_INT32],
			'y1' =>					['type' => API_INT32],
			'x2' =>					['type' => API_INT32],
			'y2' =>					['type' => API_INT32],
			'line_type' =>			['type' => API_INT32, 'in' => implode(',', [SYSMAP_SHAPE_BORDER_TYPE_NONE, SYSMAP_SHAPE_BORDER_TYPE_SOLID, SYSMAP_SHAPE_BORDER_TYPE_DOTTED, SYSMAP_SHAPE_BORDER_TYPE_DASHED])],
			'line_width' =>			['type' => API_INT32, 'in' => '0:50'],
			'line_color' =>			['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('sysmap_shape', 'border_color')],
			'zindex' =>				['type' => API_INT32]
		]];
		$default_shape_width = DB::getDefault('sysmap_shape', 'width');
		$default_shape_height = DB::getDefault('sysmap_shape', 'height');

		foreach ($sysmapids as $key => $sysmapid) {
			// Map user shares.
			if (array_key_exists('users', $maps[$key])) {
				foreach ($maps[$key]['users'] as $user) {
					$shared_users[] = [
						'sysmapid' => $sysmapid,
						'userid' => $user['userid'],
						'permission' => $user['permission']
					];
				}
			}

			// Map user group shares.
			if (array_key_exists('userGroups', $maps[$key])) {
				foreach ($maps[$key]['userGroups'] as $user_group) {
					$shared_user_groups[] = [
						'sysmapid' => $sysmapid,
						'usrgrpid' => $user_group['usrgrpid'],
						'permission' => $user_group['permission']
					];
				}
			}

			if (array_key_exists('urls', $maps[$key])) {
				foreach ($maps[$key]['urls'] as $url) {
					$url['sysmapid'] = $sysmapid;
					$urls[] = $url;
				}
			}

			if (array_key_exists('selements', $maps[$key])) {
				foreach ($maps[$key]['selements'] as $snum => $selement) {
					$maps[$key]['selements'][$snum]['sysmapid'] = $sysmapid;
				}

				$selements += $maps[$key]['selements'];
			}

			if (array_key_exists('shapes', $maps[$key])) {
				$path = '/'.($key + 1).'/shape';
				$api_shape_rules['fields']['x']['in'] = '0:'.$maps[$key]['width'];
				$api_shape_rules['fields']['y']['in'] = '0:'.$maps[$key]['height'];
				$api_shape_rules['fields']['width']['in'] = '1:'.$maps[$key]['width'];
				$api_shape_rules['fields']['height']['in'] = '1:'.$maps[$key]['height'];

				foreach ($maps[$key]['shapes'] as &$shape) {
					$shape['width'] = array_key_exists('width', $shape) ? $shape['width'] : $default_shape_width;
					$shape['height'] = array_key_exists('height', $shape) ? $shape['height'] : $default_shape_height;
				}
				unset($shape);

				if (!CApiInputValidator::validate($api_shape_rules, $maps[$key]['shapes'], $path, $error)) {
					self::exception(ZBX_API_ERROR_PARAMETERS, $error);
				}

				foreach ($maps[$key]['shapes'] as $snum => $shape) {
					$maps[$key]['shapes'][$snum]['sysmapid'] = $sysmapid;
				}

				$shapes += $maps[$key]['shapes'];
			}

			if (array_key_exists('lines', $maps[$key])) {
				$path = '/'.($key + 1).'/line';
				$api_line_rules['fields']['x1']['in'] = '0:'.$maps[$key]['width'];
				$api_line_rules['fields']['y1']['in'] = '0:'.$maps[$key]['height'];
				$api_line_rules['fields']['x2']['in'] = '0:'.$maps[$key]['width'];
				$api_line_rules['fields']['y2']['in'] = '0:'.$maps[$key]['height'];

				foreach ($maps[$key]['lines'] as &$line) {
					$line['x2'] = array_key_exists('x2', $line) ? $line['x2'] : $default_shape_width;
					$line['y2'] = array_key_exists('y2', $line) ? $line['y2'] : $default_shape_height;
				}
				unset($line);

				if (!CApiInputValidator::validate($api_line_rules, $maps[$key]['lines'], $path, $error)) {
					self::exception(ZBX_API_ERROR_PARAMETERS, $error);
				}

				foreach ($maps[$key]['lines'] as $line) {
					$shape = CMapHelper::convertLineToShape($line);
					$shape['sysmapid'] = $sysmapid;
					$shapes[] = $shape;
				}
			}

			if (array_key_exists('links', $maps[$key])) {
				foreach ($maps[$key]['links'] as $lnum => $link) {
					$maps[$key]['links'][$lnum]['sysmapid'] = $sysmapid;
				}

				$links += $maps[$key]['links'];
			}
		}

		DB::insert('sysmap_user', $shared_users);
		DB::insert('sysmap_usrgrp', $shared_user_groups);
		DB::insert('sysmap_url', $urls);

		if ($selements) {
			$selementids = $this->createSelements($selements);

			if ($links) {
				$map_virt_selements = [];
				foreach ($selementids['selementids'] as $key => $selementid) {
					$map_virt_selements[$selements[$key]['selementid']] = $selementid;
				}

				foreach ($links as $key => $link) {
					$links[$key]['selementid1'] = $map_virt_selements[$link['selementid1']];
					$links[$key]['selementid2'] = $map_virt_selements[$link['selementid2']];
				}
				unset($map_virt_selements);

				$linkids = $this->createLinks($links);

				$link_triggers = [];
				foreach ($linkids['linkids'] as $key => $linkId) {
					if (!array_key_exists('linktriggers', $links[$key])) {
						continue;
					}

					foreach ($links[$key]['linktriggers'] as $link_trigger) {
						$link_trigger['linkid'] = $linkId;
						$link_triggers[] = $link_trigger;
					}
				}

				if ($link_triggers) {
					$this->createLinkTriggers($link_triggers);
				}
			}
		}

		if ($shapes) {
			$this->createShapes($shapes);
		}

		return ['sysmapids' => $sysmapids];
	}

	/**
	 * Update map.
	 *
	 * @param array  $maps						multidimensional array with Hosts data
	 * @param string $maps['sysmapid']
	 * @param string $maps['name']
	 * @param array  $maps['width']
	 * @param int    $maps['height']
	 * @param string $maps['backgroundid']
	 * @param array  $maps['label_type']
	 * @param int    $maps['label_location']
	 * @param int    $maps['grid_size']			size of one grid cell. 100 refers to 100x100 and so on.
	 * @param int    $maps['grid_show']			does grid need to be shown. Constants: SYSMAP_GRID_SHOW_ON / SYSMAP_GRID_SHOW_OFF
	 * @param int    $maps['grid_align']		do elements need to be aligned to the grid. Constants: SYSMAP_GRID_ALIGN_ON / SYSMAP_GRID_ALIGN_OFF
	 *
	 * @return array
	 */
	public function update(array $maps) {
		$maps = zbx_toArray($maps);
		$sysmapids = zbx_objectValues($maps, 'sysmapid');

		$db_maps = $this->get([
			'output' => API_OUTPUT_EXTEND,
			'sysmapids' => zbx_objectValues($maps, 'sysmapid'),
			'selectLinks' => API_OUTPUT_EXTEND,
			'selectSelements' => API_OUTPUT_EXTEND,
			'selectShapes' => ['sysmap_shapeid', 'type', 'x', 'y', 'width', 'height', 'text', 'font', 'font_size',
				'font_color', 'text_halign', 'text_valign', 'border_type', 'border_width', 'border_color',
				'background_color', 'zindex'
			],
			'selectLines' => ['sysmap_shapeid', 'x1', 'y1', 'x2', 'y2', 'line_type', 'line_width', 'line_color',
				'zindex'
			],
			'selectUrls' => ['sysmapid', 'sysmapurlid', 'name', 'url'],
			'selectUsers' => ['sysmapuserid', 'sysmapid', 'userid', 'permission'],
			'selectUserGroups' => ['sysmapusrgrpid', 'sysmapid', 'usrgrpid', 'permission'],
			'editable' => true,
			'preservekeys' => true
		]);

		$this->validateUpdate($maps, $db_maps);

		$update_maps = [];
		$url_ids_to_delete = [];
		$urls_to_update = [];
		$urls_to_add = [];
		$selements_to_delete = [];
		$selements_to_update = [];
		$selements_to_add = [];
		$shapes_to_delete = [];
		$shapes_to_update = [];
		$shapes_to_add = [];
		$links_to_delete = [];
		$links_to_update = [];
		$links_to_add = [];
		$shared_userids_to_delete = [];
		$shared_users_to_update = [];
		$shared_users_to_add = [];
		$shared_user_groupids_to_delete = [];
		$shared_user_groups_to_update = [];
		$shared_user_groups_to_add = [];
		$api_shape_rules = ['type' => API_OBJECTS, 'flags' => API_NORMALIZE, 'fields' => [
			'sysmap_shapeid' =>		['type' => API_ID],
			'type' =>				['type' => API_INT32, 'in' => implode(',', [SYSMAP_SHAPE_TYPE_RECTANGLE, SYSMAP_SHAPE_TYPE_ELLIPSE])],
			'x' =>					['type' => API_INT32],
			'y' =>					['type' => API_INT32],
			'width' =>				['type' => API_INT32],
			'height' =>				['type' => API_INT32],
			'font' =>				['type' => API_INT32, 'in' => '0:12'],
			'font_size' =>			['type' => API_INT32, 'in' => '1:250'],
			'text_halign' =>		['type' => API_INT32, 'in' => implode(',', [SYSMAP_SHAPE_LABEL_HALIGN_CENTER, SYSMAP_SHAPE_LABEL_HALIGN_LEFT, SYSMAP_SHAPE_LABEL_HALIGN_RIGHT])],
			'text_valign' =>		['type' => API_INT32, 'in' => implode(',', [SYSMAP_SHAPE_LABEL_VALIGN_MIDDLE, SYSMAP_SHAPE_LABEL_VALIGN_TOP, SYSMAP_SHAPE_LABEL_VALIGN_BOTTOM])],
			'border_type' =>		['type' => API_INT32, 'in' => implode(',', [SYSMAP_SHAPE_BORDER_TYPE_NONE, SYSMAP_SHAPE_BORDER_TYPE_SOLID, SYSMAP_SHAPE_BORDER_TYPE_DOTTED, SYSMAP_SHAPE_BORDER_TYPE_DASHED])],
			'border_width' =>		['type' => API_INT32, 'in' => '0:50'],
			'border_color' =>		['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('sysmap_shape', 'border_color')],
			'background_color' =>	['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('sysmap_shape', 'background_color')],
			'font_color' =>			['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('sysmap_shape', 'font_color')],
			'text' =>				['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('sysmap_shape', 'text')],
			'zindex' =>				['type' => API_INT32]
		]];
		$api_line_rules = ['type' => API_OBJECTS, 'flags' => API_NORMALIZE, 'fields' => [
			'sysmap_shapeid' =>		['type' => API_ID],
			'x1' =>					['type' => API_INT32],
			'y1' =>					['type' => API_INT32],
			'x2' =>					['type' => API_INT32],
			'y2' =>					['type' => API_INT32],
			'line_type' =>			['type' => API_INT32, 'in' => implode(',', [SYSMAP_SHAPE_BORDER_TYPE_NONE, SYSMAP_SHAPE_BORDER_TYPE_SOLID, SYSMAP_SHAPE_BORDER_TYPE_DOTTED, SYSMAP_SHAPE_BORDER_TYPE_DASHED])],
			'line_width' =>			['type' => API_INT32, 'in' => '0:50'],
			'line_color' =>			['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('sysmap_shape', 'border_color')],
			'zindex' =>				['type' => API_INT32]
		]];
		$default_shape_width = DB::getDefault('sysmap_shape', 'width');
		$default_shape_height = DB::getDefault('sysmap_shape', 'height');

		foreach ($maps as $index => $map) {
			$update_maps[] = [
				'values' => $map,
				'where' => ['sysmapid' => $map['sysmapid']],
			];

			$db_map = $db_maps[$map['sysmapid']];

			// Map user shares.
			if (array_key_exists('users', $map)) {
				$user_shares_diff = zbx_array_diff($map['users'], $db_map['users'], 'userid');

				foreach ($user_shares_diff['both'] as $update_user_share) {
					$shared_users_to_update[] = [
						'values' => $update_user_share,
						'where' => ['userid' => $update_user_share['userid'], 'sysmapid' => $map['sysmapid']]
					];
				}

				foreach ($user_shares_diff['first'] as $new_shared_user) {
					$new_shared_user['sysmapid'] = $map['sysmapid'];
					$shared_users_to_add[] = $new_shared_user;
				}

				$shared_userids_to_delete = array_merge($shared_userids_to_delete,
					zbx_objectValues($user_shares_diff['second'], 'sysmapuserid')
				);
			}

			// Map user group shares.
			if (array_key_exists('userGroups', $map)) {
				$user_group_shares_diff = zbx_array_diff($map['userGroups'], $db_map['userGroups'],
					'usrgrpid'
				);

				foreach ($user_group_shares_diff['both'] as $update_user_share) {
					$shared_user_groups_to_update[] = [
						'values' => $update_user_share,
						'where' => ['usrgrpid' => $update_user_share['usrgrpid'], 'sysmapid' => $map['sysmapid']]
					];
				}

				foreach ($user_group_shares_diff['first'] as $new_shared_user_group) {
					$new_shared_user_group['sysmapid'] = $map['sysmapid'];
					$shared_user_groups_to_add[] = $new_shared_user_group;
				}

				$shared_user_groupids_to_delete = array_merge($shared_user_groupids_to_delete,
					zbx_objectValues($user_group_shares_diff['second'], 'sysmapusrgrpid')
				);
			}

			// Urls.
			if (array_key_exists('urls', $map)) {
				$url_diff = zbx_array_diff($map['urls'], $db_map['urls'], 'name');

				foreach ($url_diff['both'] as $updateUrl) {
					$urls_to_update[] = [
						'values' => $updateUrl,
						'where' => ['name' => $updateUrl['name'], 'sysmapid' => $map['sysmapid']]
					];
				}

				foreach ($url_diff['first'] as $new_url) {
					$new_url['sysmapid'] = $map['sysmapid'];
					$urls_to_add[] = $new_url;
				}

				$url_ids_to_delete = array_merge($url_ids_to_delete,
					zbx_objectValues($url_diff['second'], 'sysmapurlid')
				);
			}

			// Map elements.
			if (array_key_exists('selements', $map)) {
				$selement_diff = zbx_array_diff($map['selements'], $db_map['selements'], 'selementid');

				// We need sysmapid for add operations.
				foreach ($selement_diff['first'] as $new_selement) {
					$new_selement['sysmapid'] = $map['sysmapid'];
					$selements_to_add[] = $new_selement;
				}

				$selements_to_update = array_merge($selements_to_update, $selement_diff['both']);
				$selements_to_delete = array_merge($selements_to_delete, $selement_diff['second']);
			}

			$map_width = array_key_exists('width', $map) ? $map['width'] : $db_map['width'];
			$map_height = array_key_exists('height', $map) ? $map['height'] : $db_map['height'];

			// Map shapes.
			if (array_key_exists('shapes', $map)) {
				$map['shapes'] = array_values($map['shapes']);

				foreach ($map['shapes'] as &$shape) {
					$shape['width'] = array_key_exists('width', $shape) ? $shape['width'] : $default_shape_width;
					$shape['height'] = array_key_exists('height', $shape) ? $shape['height'] : $default_shape_height;
				}
				unset($shape);

				$shape_diff = zbx_array_diff($map['shapes'], $db_map['shapes'], 'sysmap_shapeid');

				$path = '/'.($index + 1).'/shape';
				foreach ($shape_diff['first'] as $new_shape) {
					if (array_key_exists('sysmap_shapeid', $new_shape)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_('No permissions to referred object or it does not exist!')
						);
					}
				}

				unset($api_shape_rules['fields']['sysmap_shapeid']);
				$api_shape_rules['fields']['type']['flags'] = API_REQUIRED;
				$api_shape_rules['fields']['x']['in'] = '0:'.$map_width;
				$api_shape_rules['fields']['y']['in'] = '0:'.$map_height;
				$api_shape_rules['fields']['width']['in'] = '1:'.$map_width;
				$api_shape_rules['fields']['height']['in'] = '1:'.$map_height;

				if (!CApiInputValidator::validate($api_shape_rules, $shape_diff['first'], $path, $error)) {
					self::exception(ZBX_API_ERROR_PARAMETERS, $error);
				}

				$api_shape_rules['fields']['sysmap_shapeid'] = ['type' => API_ID, 'flags' => API_REQUIRED];
				$api_shape_rules['fields']['type']['flags'] = 0;
				if (!CApiInputValidator::validate($api_shape_rules, $shape_diff['both'], $path, $error)) {
					self::exception(ZBX_API_ERROR_PARAMETERS, $error);
				}

				$shapes_to_update = array_merge($shapes_to_update, $shape_diff['both']);
				$shapes_to_delete = array_merge($shapes_to_delete, $shape_diff['second']);

				// We need sysmapid for add operations.
				foreach ($shape_diff['first'] as $new_shape) {
					$new_shape['sysmapid'] = $map['sysmapid'];
					$shapes_to_add[] = $new_shape;
				}
			}

			if (array_key_exists('lines', $map)) {
				$map['lines'] = array_values($map['lines']);
				$shapes = [];

				$api_line_rules['fields']['x1']['in'] = '0:'.$map_width;
				$api_line_rules['fields']['y1']['in'] = '0:'.$map_height;
				$api_line_rules['fields']['x2']['in'] = '0:'.$map_width;
				$api_line_rules['fields']['y2']['in'] = '0:'.$map_height;

				$path = '/'.($index + 1).'/line';

				foreach ($map['lines'] as &$line) {
					$line['x2'] = array_key_exists('x2', $line) ? $line['x2'] : $default_shape_width;
					$line['y2'] = array_key_exists('y2', $line) ? $line['y2'] : $default_shape_height;
				}
				unset($line);

				if (!CApiInputValidator::validate($api_line_rules, $map['lines'], $path, $error)) {
					self::exception(ZBX_API_ERROR_PARAMETERS, $error);
				}

				foreach ($map['lines'] as $line) {
					$shapes[] = CMapHelper::convertLineToShape($line);
				}

				$line_diff = zbx_array_diff($shapes, $db_map['lines'], 'sysmap_shapeid');

				foreach ($line_diff['first'] as $new_line) {
					if (array_key_exists('sysmap_shapeid', $new_line)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_('No permissions to referred object or it does not exist!')
						);
					}
				}

				$shapes_to_update = array_merge($shapes_to_update, $line_diff['both']);
				$shapes_to_delete = array_merge($shapes_to_delete, $line_diff['second']);

				// We need sysmapid for add operations.
				foreach ($line_diff['first'] as $new_shape) {
					$new_shape['sysmapid'] = $map['sysmapid'];
					$shapes_to_add[] = $new_shape;
				}
			}

			// Links.
			if (array_key_exists('links', $map)) {
				$link_diff = zbx_array_diff($map['links'], $db_map['links'], 'linkid');

				// We need sysmapId for add operations.
				foreach ($link_diff['first'] as $newLink) {
					$newLink['sysmapid'] = $map['sysmapid'];

					$links_to_add[] = $newLink;
				}

				$links_to_update = array_merge($links_to_update, $link_diff['both']);
				$links_to_delete = array_merge($links_to_delete, $link_diff['second']);
			}
		}

		DB::update('sysmaps', $update_maps);

		// User shares.
		DB::insert('sysmap_user', $shared_users_to_add);
		DB::update('sysmap_user', $shared_users_to_update);

		if ($shared_userids_to_delete) {
			DB::delete('sysmap_user', ['sysmapuserid' => $shared_userids_to_delete]);
		}

		// User group shares.
		DB::insert('sysmap_usrgrp', $shared_user_groups_to_add);
		DB::update('sysmap_usrgrp', $shared_user_groups_to_update);

		if ($shared_user_groupids_to_delete) {
			DB::delete('sysmap_usrgrp', ['sysmapusrgrpid' => $shared_user_groupids_to_delete]);
		}

		// Urls.
		DB::insert('sysmap_url', $urls_to_add);
		DB::update('sysmap_url', $urls_to_update);

		if ($url_ids_to_delete) {
			DB::delete('sysmap_url', ['sysmapurlid' => $url_ids_to_delete]);
		}

		// Selements.
		$new_selementids = ['selementids' => []];
		if ($selements_to_add) {
			$new_selementids = $this->createSelements($selements_to_add);
		}

		if ($selements_to_update) {
			$this->updateSelements($selements_to_update);
		}

		if ($selements_to_delete) {
			$this->deleteSelements($selements_to_delete);
		}

		if ($shapes_to_add) {
			$this->createShapes($shapes_to_add);
		}

		if ($shapes_to_update) {
			$this->updateShapes($shapes_to_update);
		}

		if ($shapes_to_delete) {
			$this->deleteShapes($shapes_to_delete);
		}

		// Links.
		if ($links_to_add || $links_to_update) {
			$selements_names = [];
			foreach ($new_selementids['selementids'] as $key => $selementId) {
				$selements_names[$selements_to_add[$key]['selementid']] = $selementId;
			}

			foreach ($selements_to_update as $selement) {
				$selements_names[$selement['selementid']] = $selement['selementid'];
			}

			foreach ($links_to_add as $key => $link) {
				if (array_key_exists($link['selementid1'], $selements_names)) {
					$links_to_add[$key]['selementid1'] = $selements_names[$link['selementid1']];
				}
				if (array_key_exists($link['selementid2'], $selements_names)) {
					$links_to_add[$key]['selementid2'] = $selements_names[$link['selementid2']];
				}
			}

			foreach ($links_to_update as $key => $link) {
				if (array_key_exists($link['selementid1'], $selements_names)) {
					$links_to_update[$key]['selementid1'] = $selements_names[$link['selementid1']];
				}
				if (array_key_exists($link['selementid2'], $selements_names)) {
					$links_to_update[$key]['selementid2'] = $selements_names[$link['selementid2']];
				}
			}

			unset($selements_names);
		}

		$new_linkids = ['linkids' => []];
		$update_linkids = ['linkids' => []];

		if ($links_to_add) {
			$new_linkids = $this->createLinks($links_to_add);
		}

		if ($links_to_update) {
			$update_linkids = $this->updateLinks($links_to_update);
		}

		if ($links_to_delete) {
			$this->deleteLinks($links_to_delete);
		}

		// Link triggers.
		$link_triggers_to_delete = [];
		$link_triggers_to_update = [];
		$link_triggers_to_add = [];

		foreach ($new_linkids['linkids'] as $key => $linkid) {
			if (!array_key_exists('linktriggers', $links_to_add[$key])) {
				continue;
			}

			foreach ($links_to_add[$key]['linktriggers'] as $link_trigger) {
				$link_trigger['linkid'] = $linkid;
				$link_triggers_to_add[] = $link_trigger;
			}
		}

		$db_links = [];

		$link_trigger_resource = DBselect(
			'SELECT slt.* FROM sysmaps_link_triggers slt WHERE '.dbConditionInt('slt.linkid', $update_linkids['linkids'])
		);
		while ($db_link_trigger = DBfetch($link_trigger_resource)) {
			zbx_subarray_push($db_links, $db_link_trigger['linkid'], $db_link_trigger);
		}

		foreach ($update_linkids['linkids'] as $key => $linkid) {
			if (!array_key_exists('linktriggers', $links_to_update[$key])) {
				continue;
			}

			$db_link_triggers = array_key_exists($linkid, $db_links) ? $db_links[$linkid] : [];
			$db_link_triggers_diff = zbx_array_diff($links_to_update[$key]['linktriggers'],
				$db_link_triggers, 'linktriggerid'
			);

			foreach ($db_link_triggers_diff['first'] as $new_link_trigger) {
				$new_link_trigger['linkid'] = $linkid;
				$link_triggers_to_add[] = $new_link_trigger;
			}

			$link_triggers_to_update = array_merge($link_triggers_to_update, $db_link_triggers_diff['both']);
			$link_triggers_to_delete = array_merge($link_triggers_to_delete, $db_link_triggers_diff['second']);
		}

		if ($link_triggers_to_delete) {
			$this->deleteLinkTriggers($link_triggers_to_delete);
		}

		if ($link_triggers_to_add) {
			$this->createLinkTriggers($link_triggers_to_add);
		}

		if ($link_triggers_to_update) {
			$this->updateLinkTriggers($link_triggers_to_update);
		}

		return ['sysmapids' => $sysmapids];
	}

	/**
	 * Delete Map.
	 *
	 * @param array $sysmapids
	 *
	 * @return array
	 */
	public function delete(array $sysmapids) {
		$this->validateDelete($sysmapids);

		DB::delete('sysmaps_elements', [
			'elementid' => $sysmapids,
			'elementtype' => SYSMAP_ELEMENT_TYPE_MAP
		]);
		DB::delete('screens_items', [
			'resourceid' => $sysmapids,
			'resourcetype' => SCREEN_RESOURCE_MAP
		]);
		DB::delete('profiles', [
			'idx' => 'web.maps.sysmapid',
			'value_id' => $sysmapids
		]);
		DB::delete('profiles', [
			'idx' => 'web.favorite.sysmapids',
			'source' => 'sysmapid',
			'value_id' => $sysmapids
		]);
		DB::delete('sysmaps', ['sysmapid' => $sysmapids]);

		return ['sysmapids' => $sysmapids];
	}

	private function expandUrlMacro($url, $selement) {
		switch ($selement['elementtype']) {
			case SYSMAP_ELEMENT_TYPE_HOST_GROUP:
				$macro = '{HOSTGROUP.ID}';
				break;

			case SYSMAP_ELEMENT_TYPE_TRIGGER:
				$macro = '{TRIGGER.ID}';
				break;

			case SYSMAP_ELEMENT_TYPE_MAP:
				$macro = '{MAP.ID}';
				break;

			case SYSMAP_ELEMENT_TYPE_HOST:
				$macro = '{HOST.ID}';
				break;

			default:
				$macro = false;
		}

		if ($macro) {
			$url['url'] = str_replace($macro, $selement['elementid'], $url['url']);
		}

		return $url;
	}

	protected function addRelatedObjects(array $options, array $result) {
		$result = parent::addRelatedObjects($options, $result);

		$sysmapIds = array_keys($result);

		// adding elements
		if ($options['selectSelements'] !== null && $options['selectSelements'] != API_OUTPUT_COUNT) {
			$selements = API::getApiService()->select('sysmaps_elements', [
				'output' => $this->outputExtend($options['selectSelements'], ['selementid', 'sysmapid', 'elementtype',
					'elementid'
				]),
				'filter' => ['sysmapid' => $sysmapIds],
				'preservekeys' => true
			]);
			$relation_map = $this->createRelationMap($selements, 'sysmapid', 'selementid');

			// add selement URLs
			if ($this->outputIsRequested('urls', $options['selectSelements'])) {
				foreach ($selements as &$selement) {
					$selement['urls'] = [];
				}
				unset($selement);

				if (!is_null($options['expandUrls'])) {
					$dbMapUrls = DBselect(
						'SELECT su.sysmapurlid,su.sysmapid,su.name,su.url,su.elementtype'.
						' FROM sysmap_url su'.
						' WHERE '.dbConditionInt('su.sysmapid', $sysmapIds)
					);
					while ($mapUrl = DBfetch($dbMapUrls)) {
						foreach ($selements as $snum => $selement) {
							if (bccomp($selement['sysmapid'], $mapUrl['sysmapid']) == 0
									&& (($selement['elementtype'] == $mapUrl['elementtype']
											&& $selement['elementsubtype'] == SYSMAP_ELEMENT_SUBTYPE_HOST_GROUP
											)
											|| ($selement['elementsubtype'] == SYSMAP_ELEMENT_SUBTYPE_HOST_GROUP_ELEMENTS
													&& $mapUrl['elementtype'] == SYSMAP_ELEMENT_TYPE_HOST)
										)) {
								$selements[$snum]['urls'][] = $this->expandUrlMacro($mapUrl, $selement);
							}
						}
					}
				}

				$dbSelementUrls = DBselect(
					'SELECT seu.sysmapelementurlid,seu.selementid,seu.name,seu.url'.
					' FROM sysmap_element_url seu'.
					' WHERE '.dbConditionInt('seu.selementid', array_keys($selements))
				);
				while ($selementUrl = DBfetch($dbSelementUrls)) {
					$selements[$selementUrl['selementid']]['urls'][] = is_null($options['expandUrls'])
						? $selementUrl
						: $this->expandUrlMacro($selementUrl, $selements[$selementUrl['selementid']]);
				}
			}

			if ($this->outputIsRequested('elements', $options['selectSelements']) && $selements) {
				foreach ($selements as &$selement) {
					$selement['elements'] = [];
				}
				unset($selement);

				$selement_triggers = API::getApiService()->select('sysmap_element_trigger', [
					'output' => ['selementid', 'triggerid'],
					'filter' => ['selementid' => array_keys($selements)]
				]);
				foreach ($selement_triggers as $selement_trigger) {
					$selements[$selement_trigger['selementid']]['elements'][] = [
						'triggerid' => $selement_trigger['triggerid']
					];
				}

				$single_element_types = [SYSMAP_ELEMENT_TYPE_HOST, SYSMAP_ELEMENT_TYPE_MAP,
					SYSMAP_ELEMENT_TYPE_HOST_GROUP
				];

				foreach ($selements as &$selement) {
					if (in_array($selement['elementtype'], $single_element_types)) {
						switch ($selement['elementtype']) {
							case SYSMAP_ELEMENT_TYPE_HOST_GROUP:
								$field = 'groupid';
								break;

							case SYSMAP_ELEMENT_TYPE_HOST:
								$field = 'hostid';
								break;

							case SYSMAP_ELEMENT_TYPE_MAP:
								$field = 'sysmapid';
								break;
						}
						$selement['elements'][] = [$field => $selement['elementid']];
					}
				}
				unset($selement);
			}

			if ($this->outputIsRequested('permission', $options['selectSelements']) && $selements) {
				if ($options['editable']) {
					foreach ($selements as &$selement) {
						$selement['permission'] = PERM_READ_WRITE;
					}
					unset($selement);
				}
				elseif (self::$userData['type'] == USER_TYPE_SUPER_ADMIN) {
					foreach ($selements as &$selement) {
						$selement['permission'] = PERM_READ;
					}
					unset($selement);
				}
				else {
					$ids = [
						SYSMAP_ELEMENT_TYPE_HOST_GROUP => [],
						SYSMAP_ELEMENT_TYPE_HOST => [],
						SYSMAP_ELEMENT_TYPE_TRIGGER => [],
						SYSMAP_ELEMENT_TYPE_MAP => []
					];
					$trigger_selementids = [];

					foreach ($selements as &$selement) {
						switch ($selement['elementtype']) {
							case SYSMAP_ELEMENT_TYPE_HOST_GROUP:
							case SYSMAP_ELEMENT_TYPE_HOST:
							case SYSMAP_ELEMENT_TYPE_MAP:
								$ids[$selement['elementtype']][$selement['elementid']][] = $selement['selementid'];
								$selement['permission'] = PERM_NONE;
								break;

							case SYSMAP_ELEMENT_TYPE_TRIGGER:
								$trigger_selementids[$selement['selementid']] = true;
								$selement['permission'] = PERM_NONE;
								break;

							case SYSMAP_ELEMENT_TYPE_IMAGE:
								$selement['permission'] = PERM_READ;
								break;
						}
					}
					unset($selement);

					$db[SYSMAP_ELEMENT_TYPE_HOST_GROUP] = $ids[SYSMAP_ELEMENT_TYPE_HOST_GROUP]
						? API::HostGroup()->get([
							'output' => [],
							'groupids' => array_keys($ids[SYSMAP_ELEMENT_TYPE_HOST_GROUP]),
							'preservekeys' => true
						])
						: [];

					$db[SYSMAP_ELEMENT_TYPE_HOST] = $ids[SYSMAP_ELEMENT_TYPE_HOST]
						? API::Host()->get([
							'output' => [],
							'hostids' => array_keys($ids[SYSMAP_ELEMENT_TYPE_HOST]),
							'preservekeys' => true
						])
					: [];

					$db[SYSMAP_ELEMENT_TYPE_MAP] = $ids[SYSMAP_ELEMENT_TYPE_MAP]
						? API::Map()->get([
							'output' => [],
							'sysmapids' => array_keys($ids[SYSMAP_ELEMENT_TYPE_MAP]),
							'preservekeys' => true
						])
						: [];

					if ($trigger_selementids) {
						$db_selement_triggers = DBselect(
							'SELECT st.selementid,st.triggerid'.
							' FROM sysmap_element_trigger st'.
							' WHERE '.dbConditionInt('st.selementid', array_keys($trigger_selementids))
						);

						while ($db_selement_trigger = DBfetch($db_selement_triggers)) {
							$ids[SYSMAP_ELEMENT_TYPE_TRIGGER][$db_selement_trigger['triggerid']][] =
								$db_selement_trigger['selementid'];
						}
					}

					$db[SYSMAP_ELEMENT_TYPE_TRIGGER] = $ids[SYSMAP_ELEMENT_TYPE_TRIGGER]
						? API::Trigger()->get([
							'output' => [],
							'triggerids' => array_keys($ids[SYSMAP_ELEMENT_TYPE_TRIGGER]),
							'preservekeys' => true
						])
						: [];

					foreach ($ids as $elementtype => $elementids) {
						foreach ($elementids as $elementid => $selementids) {
							if (array_key_exists($elementid, $db[$elementtype])) {
								foreach ($selementids as $selementid) {
									$selements[$selementid]['permission'] = PERM_READ;
								}
							}
						}
					}
				}
			}

			foreach ($selements as &$selement) {
				unset($selement['elementid']);
			}
			unset($selement);

			$selements = $this->unsetExtraFields($selements, ['sysmapid', 'selementid', 'elementtype'],
				$options['selectSelements']
			);
			$result = $relation_map->mapMany($result, $selements, 'selements');
		}

		$shape_types = [];
		if ($options['selectShapes'] !== null && $options['selectShapes'] != API_OUTPUT_COUNT) {
			$shape_types = [SYSMAP_SHAPE_TYPE_RECTANGLE, SYSMAP_SHAPE_TYPE_ELLIPSE];
		}

		if ($options['selectLines'] !== null && $options['selectLines'] != API_OUTPUT_COUNT) {
			$shape_types[] = SYSMAP_SHAPE_TYPE_LINE;
		}

		// Adding shapes.
		if ($shape_types) {
			$fields = API_OUTPUT_EXTEND;

			if ($options['selectShapes'] != API_OUTPUT_EXTEND && $options['selectLines'] != API_OUTPUT_EXTEND) {
				$fields = ['sysmap_shapeid', 'sysmapid', 'type'];
				$mapping = [
					'x1' => 'x',
					'y1' => 'y',
					'x2' => 'width',
					'y2' =>	'height',
					'line_type' => 'border_type',
					'line_width' => 'border_width',
					'line_color' => 'border_color'
				];

				if (is_array($options['selectLines'])) {
					foreach ($mapping as $source_field => $target_field) {
						if (in_array($source_field, $options['selectLines'])) {
							$fields[] = $target_field;
						}
					}
				}

				if (is_array($options['selectShapes'])) {
					$fields = array_merge($fields, $options['selectShapes']);
				}
			}

			$db_shapes = API::getApiService()->select('sysmap_shape', [
				'output' => $fields,
				'filter' => ['sysmapid' => $sysmapIds, 'type' => $shape_types],
				'preservekeys' => true
			]);

			$shapes = [];
			$lines = [];
			foreach ($db_shapes as $key => $db_shape) {
				if ($db_shape['type'] == SYSMAP_SHAPE_TYPE_LINE) {
					$lines[$key] = CMapHelper::convertShapeToLine($db_shape);
				}
				else {
					$shapes[$key] = $db_shape;
				}
			}

			$relation_map = $this->createRelationMap($db_shapes, 'sysmapid', 'sysmap_shapeid');

			if ($options['selectShapes'] !== null && $options['selectShapes'] != API_OUTPUT_COUNT) {
				$shapes = $this->unsetExtraFields($shapes, ['sysmap_shapeid', 'type', 'x', 'y', 'width', 'height',
					'text', 'font', 'font_size', 'font_color', 'text_halign', 'text_valign', 'border_type',
					'border_width', 'border_color', 'background_color', 'zindex'
				], $options['selectShapes']);
				$shapes = $this->unsetExtraFields($shapes, ['sysmapid'], null);

				$result = $relation_map->mapMany($result, $shapes, 'shapes');
			}

			if ($options['selectLines'] !== null && $options['selectLines'] != API_OUTPUT_COUNT) {
				$lines = $this->unsetExtraFields($lines, ['sysmap_shapeid', 'x1', 'x2', 'y1', 'y2', 'line_type',
					'line_width', 'line_color', 'zindex'
				], $options['selectLines']);
				$lines = $this->unsetExtraFields($lines, ['sysmapid', 'type'], null);

				$result = $relation_map->mapMany($result, $lines, 'lines');
			}
		}

		// adding icon maps
		if ($options['selectIconMap'] !== null && $options['selectIconMap'] != API_OUTPUT_COUNT) {
			$iconMaps = API::getApiService()->select($this->tableName(), [
				'output' => ['sysmapid', 'iconmapid'],
				'filter' => ['sysmapid' => $sysmapIds]
			]);

			$relation_map = $this->createRelationMap($iconMaps, 'sysmapid', 'iconmapid');

			$iconMaps = API::IconMap()->get([
				'output' => $this->outputExtend($options['selectIconMap'], ['iconmapid']),
				'iconmapids' => zbx_objectValues($iconMaps, 'iconmapid'),
				'preservekeys' => true
			]);

			$iconMaps = $this->unsetExtraFields($iconMaps, ['iconmapid'], $options['selectIconMap']);

			$result = $relation_map->mapOne($result, $iconMaps, 'iconmap');
		}

		// adding links
		if ($options['selectLinks'] !== null && $options['selectLinks'] != API_OUTPUT_COUNT) {
			$links = API::getApiService()->select('sysmaps_links', [
				'output' => $this->outputExtend($options['selectLinks'], ['sysmapid', 'linkid']),
				'filter' => ['sysmapid' => $sysmapIds],
				'preservekeys' => true
			]);
			$relation_map = $this->createRelationMap($links, 'sysmapid', 'linkid');

			// add link triggers
			if ($this->outputIsRequested('linktriggers', $options['selectLinks'])) {
				$linkTriggers = DBFetchArrayAssoc(DBselect(
					'SELECT DISTINCT slt.*'.
					' FROM sysmaps_link_triggers slt'.
					' WHERE '.dbConditionInt('slt.linkid', $relation_map->getRelatedIds())
				), 'linktriggerid');
				$linkTriggerRelationMap = $this->createRelationMap($linkTriggers, 'linkid', 'linktriggerid');
				$links = $linkTriggerRelationMap->mapMany($links, $linkTriggers, 'linktriggers');
			}

			if ($this->outputIsRequested('permission', $options['selectLinks']) && $links) {
				if ($options['editable']) {
					foreach ($links as &$link) {
						$link['permission'] = PERM_READ_WRITE;
					}
					unset($link);
				}
				elseif (self::$userData['type'] == USER_TYPE_SUPER_ADMIN) {
					foreach ($links as &$link) {
						$link['permission'] = PERM_READ;
					}
					unset($link);
				}
				else {
					foreach ($links as &$link) {
						$link['permission'] = PERM_NONE;
					}
					unset($link);

					$db_link_triggers = DBselect(
						'SELECT slt.linkid,slt.triggerid'.
						' FROM sysmaps_link_triggers slt'.
						' WHERE '.dbConditionInt('slt.linkid', array_keys($links))
					);

					$triggerids = [];

					while ($db_link_trigger = DBfetch($db_link_triggers)) {
						$triggerids[$db_link_trigger['triggerid']][] = $db_link_trigger['linkid'];
					}

					$db_triggers = $triggerids
						? API::Trigger()->get([
							'output' => [],
							'triggerids' => array_keys($triggerids),
							'preservekeys' => true
						])
						: [];

					foreach ($triggerids as $triggerid => $linkids) {
						if (array_key_exists($triggerid, $db_triggers)) {
							foreach ($linkids as $linkid) {
								$links[$linkid]['permission'] = PERM_READ;
							}
						}
					}
				}
			}

			$links = $this->unsetExtraFields($links, ['sysmapid', 'linkid'], $options['selectLinks']);
			$result = $relation_map->mapMany($result, $links, 'links');
		}

		// adding urls
		if ($options['selectUrls'] !== null && $options['selectUrls'] != API_OUTPUT_COUNT) {
			$links = API::getApiService()->select('sysmap_url', [
				'output' => $this->outputExtend($options['selectUrls'], ['sysmapid', 'sysmapurlid']),
				'filter' => ['sysmapid' => $sysmapIds],
				'preservekeys' => true
			]);
			$relation_map = $this->createRelationMap($links, 'sysmapid', 'sysmapurlid');

			$links = $this->unsetExtraFields($links, ['sysmapid', 'sysmapurlid'], $options['selectUrls']);
			$result = $relation_map->mapMany($result, $links, 'urls');
		}

		// Adding user shares.
		if ($options['selectUsers'] !== null && $options['selectUsers'] != API_OUTPUT_COUNT) {
			$relation_map = $this->createRelationMap($result, 'sysmapid', 'userid', 'sysmap_user');
			// Get all allowed users.
			$related_users = API::User()->get([
				'output' => ['userid'],
				'userids' => $relation_map->getRelatedIds(),
				'preservekeys' => true
			]);

			$related_userids = zbx_objectValues($related_users, 'userid');

			if ($related_userids) {
				$users = API::getApiService()->select('sysmap_user', [
					'output' => $this->outputExtend($options['selectUsers'], ['sysmapid', 'userid']),
					'filter' => ['sysmapid' => $sysmapIds, 'userid' => $related_userids],
					'preservekeys' => true
				]);

				$relation_map = $this->createRelationMap($users, 'sysmapid', 'sysmapuserid');

				$users = $this->unsetExtraFields($users, ['sysmapuserid', 'userid', 'permission'],
					$options['selectUsers']
				);

				foreach ($users as &$user) {
					unset($user['sysmapid']);
				}
				unset($user);

				$result = $relation_map->mapMany($result, $users, 'users');
			}
			else {
				foreach ($result as &$row) {
					$row['users'] = [];
				}
				unset($row);
			}
		}

		// Adding user group shares.
		if ($options['selectUserGroups'] !== null && $options['selectUserGroups'] != API_OUTPUT_COUNT) {
			$relation_map = $this->createRelationMap($result, 'sysmapid', 'usrgrpid', 'sysmap_usrgrp');
			// Get all allowed groups.
			$related_groups = API::UserGroup()->get([
				'output' => ['usrgrpid'],
				'usrgrpids' => $relation_map->getRelatedIds(),
				'preservekeys' => true
			]);

			$related_groupids = zbx_objectValues($related_groups, 'usrgrpid');

			if ($related_groupids) {
				$user_groups = API::getApiService()->select('sysmap_usrgrp', [
					'output' => $this->outputExtend($options['selectUserGroups'], ['sysmapid', 'usrgrpid']),
					'filter' => ['sysmapid' => $sysmapIds, 'usrgrpid' => $related_groupids],
					'preservekeys' => true
				]);

				$relation_map = $this->createRelationMap($user_groups, 'sysmapid', 'sysmapusrgrpid');

				$user_groups = $this->unsetExtraFields($user_groups, ['sysmapusrgrpid', 'usrgrpid', 'permission'],
					$options['selectUserGroups']
				);

				foreach ($user_groups as &$user_group) {
					unset($user_group['sysmapid']);
				}
				unset($user_group);

				$result = $relation_map->mapMany($result, $user_groups, 'userGroups');
			}
			else {
				foreach ($result as &$row) {
					$row['userGroups'] = [];
				}
				unset($row);
			}
		}

		return $result;
	}
}
