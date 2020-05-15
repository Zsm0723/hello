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

class testPageLowLevelDiscovery extends CWebTest {

        const HOST_ID = 90001;
        private $buttons_name = ['Disable', 'Enable', 'Check now', 'Delete'];

        public function testPageLowLevelDiscovery_CheckPageLayout() {
                $this->page->login()->open('host_discovery.php?&hostid='.self::HOST_ID);

                // Checking Title name.
		$this->assertPageTitle('Configuration of discovery rules');

                // Checking Header name, that we are in a right place (sure we are, because we wrote already link to this host).
                $page_title_name = $this->query('xpath://h1[@id="page-title-general"]')->one()->getText();
                $this->assertEquals('Discovery rules', $page_title_name);

                // Now, check that there is realy 3 rows displayed.
                $displayed_discovery = $this->query('xpath://div[@class="table-stats"]')->one()->getText();
                $this->assertEquals('Displaying 3 of 3 found', $displayed_discovery);

                // Here we go, with horrible, creepy, long, disgusting column value name check... Still thinking about it.
                $table_headers = ['Items', 'Triggers', 'Graphs', 'Hosts', 'Info', 'Name', 'Key', 'Interval', 'Type', 'Status'];
                foreach ($table_headers as $header) {
                $this->assertTrue($this->query('xpath://tr//*[contains(text(),"'.$header.'")]')->one()->isPresent());
            }
                // And now let's check that all buttons exists.
                foreach ($this->buttons_name as $button) {
                $this->assertTrue($this->query('xpath://button[text()="'.$button.'"]')->one()->isPresent());
            }
        }

	public function testPageLowLevelDiscovery_CheckEnableSingle() {
		$this->page->login()->open('host_discovery.php?&hostid='.self::HOST_ID);

                // Get table element.
                $table = $this->query('class:list-table')->asTable()->one();

                // Find row by column value.
                $row = $table->findRow('Name', 'Discovery rule 2')->select();

                // In this wonderful method, we press enabled/Disabled link and checking messages.
                $actions = ['Enabled', 'Disabled'];
                foreach ($actions as $action) {
                    $row->query('link:'.$action)->one()->click();
                    if ($action=='Enabled') {
                        $this->assertEquals('Discovery rule disabled', CMessageElement::find()->one()->getTitle());
                    }
                    elseif ($action=='Disabled'){
                        $this->assertEquals('Discovery rule enabled', CMessageElement::find()->one()->getTitle());
                    }
                }
            }

        public function testPageLowLevelDiscovery_CheckButtonsAll() {
                $this->page->login()->open('host_discovery.php?&hostid='.self::HOST_ID);

                // Here we press all available buttons and checking success message (if we have it).
                foreach ($this->buttons_name as $button) {
                    $this->query('id:all_items')->asCheckbox()->one()->check();
                    $this->query('button:'.$button)->one()->click();
                    if ($button=='Disable') {
                        $this->page->acceptAlert();
                        $this->assertEquals('Discovery rules disabled', CMessageElement::find()->one()->getTitle());
                    }
                    elseif ($button=='Enable') {
                        $this->page->acceptAlert();
                        $this->assertEquals('Discovery rules enabled', CMessageElement::find()->one()->getTitle());
                    }
                    elseif ($button=='Check now') {
                        $this->assertEquals('Request sent successfully', CMessageElement::find()->one()->getTitle());
                    }
                    elseif ($button=='Delete') {
                        $this->assertEquals('Delete selected discovery rules?', $this->page->getAlertText());
                        $this->page->dismissAlert();
                    }
                }
            }

        public function testPageLowLevelDiscovery_CheckButtonsSingle() {
                // Simply press "Check now" button. No rocket science
                $this->page->login()->open('host_discovery.php?&hostid='.self::HOST_ID);
                $table = $this->query('class:list-table')->asTable()->one();
                $row = $table->findRow('Name', 'Discovery rule 3')->select();
                $this->query('button:Check now')->one()->click();
                $this->assertEquals('Request sent successfully', CMessageElement::find()->one()->getTitle());
                }
            }
