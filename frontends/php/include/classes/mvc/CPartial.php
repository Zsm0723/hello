<?php
/*
** Zabbix
** Copyright (C) 2001-2020 Zabbix SIA
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
 * Class for rendering partial-views, which are useful for reusing the same partial-views or updating specific parts of
 * the page asynchronously, i.e., partials can be included into a view initially and updated in AJAX manner, later.
 */
class CPartial {

	/**
	 * Directory list of MVC partials ordered by search priority.
	 *
	 * @static
	 *
	 * @var array
	 */
	private static $directories = ['local/app/partials', 'app/partials'];

	/**
	 * Partial name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Data provided for partial.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Directory where the partial file was found.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Create a partial based on partial name and data.
	 *
	 * @param string $name  Partial name to search for.
	 * @param array  $data  Accessible data within the partial.
	 *
	 * @throws InvalidArgumentException if partial name not valid.
	 * @throws RuntimeException if partial not found or not readable.
	 */
	public function __construct($name, array $data = []) {
		if (!preg_match('/^[a-z\.\/]+$/', $name)) {
			throw new InvalidArgumentException(sprintf('Invalid partial name: "%s".', $name));
		}

		$file_path = null;

		foreach (self::$directories as $directory) {
			$file_path = $directory.'/'.$name.'.php';
			if (is_file($file_path)) {
				$this->directory = $directory;
				break;
			}
		}

		if ($this->directory === null) {
			throw new RuntimeException(_s('Partial not found: "%s".', $name));
		}

		if (!is_readable($file_path)) {
			throw new RuntimeException(_s('Partial not readable: "%s".', $file_path));
		}

		$this->name = $name;
		$this->data = $data;
	}

	/**
	 * Render partial and return the output.
	 * Note: partial should only output textual content like HTML, JSON, scripts or similar.
	 *
	 * @throws RuntimeException if partial not found, not readable or returned false.
	 *
	 * @return string
	 */
	public function getOutput() {
		$data = $this->data;

		$file_path = $this->directory.'/'.$this->name.'.php';

		ob_start();

		if ((include $file_path) === false) {
			ob_end_clean();

			throw new RuntimeException(_s('Cannot render partial: "%s".', $file_path));
		}

		return ob_get_clean();
	}

	/**
	 * Get the contents of a PHP-preprocessed JavaScript file.
	 * Notes:
	 *   - JavaScript file will be searched in the "js" subdirectory of the partial file.
	 *   - A copy of $data variable will be available for using within the file.
	 *
	 * @param string $file_name
	 *
	 * @throws RuntimeException if the file not found, not readable or returned false.
	 *
	 * @return string
	 */
	public function readJsFile($file_name) {
		$data = $this->data;

		$file_path = $this->directory.'/js/'.$file_name;

		ob_start();

		if ((include $file_path) === false) {
			ob_end_clean();

			throw new RuntimeException(_s('Cannot read file: "%s".', $file_path));
		}

		return ob_get_clean();
	}

	/**
	 * Include a PHP-preprocessed JavaScript file inline.
	 * Notes:
	 *   - JavaScript file will be searched in the "js" subdirectory of the partial file.
	 *   - A copy of $data variable will be available for using within the file.
	 *
	 * @param string $file_name
	 *
	 * @throws RuntimeException if the file not found, not readable or returned false.
	 */
	public function includeJsFile($file_name) {
		echo $this->readJsFile($file_name);
	}

	/**
	 * Add custom directory to the directory list of MVC partials. The last added will have the highest piority.
	 *
	 * @param string $directory
	 */
	public static function addDirectory($directory) {
		if (!in_array($directory, self::$directories)) {
			array_unshift(self::$directories, $directory);
		}
	}
}