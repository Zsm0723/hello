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


class CTagImporter {

	/**
	 * Tag class
	 *
	 * @var CImportXmlTagInterface
	 */
	private $tag;

	public function __construct(CImportXmlTagInterface $tag) {
		$this->tag = $tag;
	}

	public function import(array $data) {
		$handler = $this->tag->getImportHandler();
		if (is_callable($handler)) {
			return call_user_func($handler, $data, $this->tag);
		}

		if (!array_key_exists($this->tag->getTag(), $data)) {
			if ($this->tag instanceof CStringXmlTagInterface && $this->tag->getDefaultValue() !== null) {
				return (string) $this->tag->getDefaultValue();
			}

			return '';
		}

		if ($this->tag instanceof CStringXmlTagInterface && $this->tag->hasConstant()) {
			return (string) $this->tag->getConstantValueByName($data[$this->tag->getTag()]);
		}

		return (string) $data[$this->tag->getTag()];
	}
}