<?php
/*
** Zabbix
** Copyright (C) 2001-2019 Zabbix SIA
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


class CControllerValuemapUpdate extends CController {

	protected function checkInput() {
		$fields = [
			'valuemapid'   => 'fatal | required | db valuemaps.valuemapid',
			'name'         => 'required | string | not_empty | db valuemaps.name',
			'mappings'     => 'required | array',
			'form_refresh' => '',
			'page'         => 'ge 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			switch ($this->getValidationError()) {
				case self::VALIDATION_ERROR:
					$url = (new CUrl('zabbix.php'))
						->setArgument('action', 'valuemap.edit')
						->setArgument('valuemapid', $this->getInput('valuemapid'));

					$response = new CControllerResponseRedirect($url);
					$response->setFormData($this->getInputAll());
					$response->setMessageError(_('Cannot update value map'));

					$this->setResponse($response);
					break;

				case self::VALIDATION_FATAL_ERROR:
					$this->setResponse(new CControllerResponseFatal());
					break;
			}
		}

		return $ret;
	}

	protected function checkPermissions() {
		if ($this->getUserType() != USER_TYPE_SUPER_ADMIN) {
			return false;
		}

		$valuemaps = (bool) API::ValueMap()->get([
			'output' => [],
			'valuemapids' => (array) $this->getInput('valuemapid')
		]);

		if (!$valuemaps) {
			return false;
		}

		return true;
	}

	protected function doAction() {
		$result = (bool) API::ValueMap()->update([
			'name'       => $this->getInput('name'),
			'mappings'   => $this->getInput('mappings'),
			'valuemapid' => $this->getInput('valuemapid')
		]);

		if ($result) {
			$response = new CControllerResponseRedirect((new CUrl('zabbix.php'))
				->setArgument('action', 'valuemap.list')
			);
			$response->setMessageOk(_('Value map updated'));
		}
		else {
			$response = new CControllerResponseRedirect((new CUrl('zabbix.php'))
				->setArgument('action', 'valuemap.edit')
				->setArgument('valuemapid', $this->getInput('valuemapid'))
			);
			$response->setMessageError(_('Cannot update value map'));
			$response->setFormData($this->getInputAll());
		}

		$this->setResponse($response);
	}
}