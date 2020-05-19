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

require_once dirname(__FILE__).'/../include/CWebTest.php';


/**
 * @backup items
 */
class testPageLowLevelDiscovery extends CWebTest {

	const HOST_ID = 90001;
	private $buttons_name = ['Disable', 'Enable', 'Check now', 'Delete'];
	private $discovery_rule_name = 'Discovery rule 2';
	private $table_headers = ['Items', 'Triggers', 'Graphs', 'Hosts', 'Info', 'Name', 'Key', 'Interval', 'Type', 'Status'];
	private $all_discovery_rule_names = ['Discovery rule 1', 'Discovery rule 2', 'Discovery rule 3'];
	private $discovery_status = ['Enabled', 'Disabled'];

	public function testPageLowLevelDiscovery_CheckPageLayout() {
		$this->page->login()->open('host_discovery.php?&hostid='.self::HOST_ID);

		// Checking Title, Header and Column names.
		$this->assertPageTitle('Configuration of discovery rules');
		$page_title_name = $this->query('xpath://h1[@id="page-title-general"]')->one()->getText();
		$this->assertEquals('Discovery rules', $page_title_name);
		foreach ($this->table_headers as $header) {
			$this->assertTrue($this->query('xpath://tr//*[contains(text(),"'.$header.'")]')->one()->isPresent());
		}

		// Check that 3 rows displayed
		$displayed_discovery = $this->query('xpath://div[@class="table-stats"]')->one()->getText();
		$this->assertEquals('Displaying 3 of 3 found', $displayed_discovery);

		// Check buttons.
		foreach ($this->buttons_name as $button) {
			$this->assertTrue($this->query('button:'.$button)->one()->isPresent());
		}
	}

	public function testPageLowLevelDiscovery_EnableDisableSingle() {
		$this->page->login()->open('host_discovery.php?&hostid='.self::HOST_ID);
		$table = $this->query('class:list-table')->asTable()->one();
		$row = $table->findRow('Name', $this->all_discovery_rule_names[1])->select();

		// Clicking Enabled/Disabled link
		foreach ($this->discovery_status as $action) {
			$row->query('link:'.$action)->one()->click();
			$expected_status = $action === 'Enabled' ? 1 : 0;
			$status = CDBHelper::getValue('SELECT status FROM items WHERE name ='.zbx_dbstr($this->all_discovery_rule_names[1]));
			$this->assertEquals($expected_status, $status);
			$message_action = $action === 'Enabled' ? 'disabled' : 'enabled';
			$this->assertEquals('Discovery rule '.$message_action, CMessageElement::find()->one()->getTitle());
		}
	}

	public function testPageLowLevelDiscovery_EnableDisableAll() {
		$this->page->login()->open('host_discovery.php?&hostid='.self::HOST_ID);
		// Here we press all available buttons and checking success message (if we have it).
		foreach ($this->buttons_name as $button) {
			switch ($button) {
				case 'Disable':
				case 'Enable':
					$this->query('id:all_items')->asCheckbox()->one()->check();
					$this->query('button:'.$button)->one()->click();
					$this->page->acceptAlert();
					$this->assertEquals('Discovery rules '.lcfirst($button).'d', CMessageElement::find()->one()->getTitle());
					$expected_status = $button === 'Disable' ? 1 : 0;
					foreach ($this->all_discovery_rule_names as $rule_name) {
						$status = CDBHelper::getValue('SELECT status FROM items WHERE name ='.zbx_dbstr($rule_name));
						$this->assertEquals($expected_status, $status);
					break;
					}
			}
		}
	}

	public function testPageLowLevelDiscovery_CheckNow() {
		$this->page->login()->open('host_discovery.php?&hostid='.self::HOST_ID);
		$table = $this->query('class:list-table')->asTable()->one();
		$row = $table->findRow('Name', $this->all_discovery_rule_names[1])->select();

		// Clicking Check now button.
		$this->query('button:Check now')->one()->click();
		$this->assertEquals('Request sent successfully', CMessageElement::find()->one()->getTitle());
		$row->select();
		$this->query('button:Disable')->one()->click();
		$this->page->acceptAlert();
		$row->select();
		$this->query('button:Check now')->one()->click();
		$this->assertEquals('Cannot send request', CMessageElement::find()->one()->getTitle());
		$this->query('id:all_items')->asCheckbox()->one()->check();
		$this->query('button:Check now')->one()->click();
		$this->assertEquals('Cannot send request', CMessageElement::find()->one()->getTitle());
	}

	public function testPageLowLevelDiscovery_DeleteAllButton() {
		$this->page->login()->open('host_discovery.php?&hostid='.self::HOST_ID);

		// Delete all discovery rules.
		$this->query('id:all_items')->asCheckbox()->one()->check();
		$this->query('button:Delete')->one()->click();
		$this->page->acceptAlert();
		$this->assertEquals('Discovery rules deleted', CMessageElement::find()->one()->getTitle());
		foreach ($this->all_discovery_rule_names as $rule_name) {
			$count_discovery = CDBHelper::getCount('SELECT null FROM items WHERE name ='.zbx_dbstr($rule_name));
			$this->assertEquals(0, $count_discovery);
		}
	}
}
