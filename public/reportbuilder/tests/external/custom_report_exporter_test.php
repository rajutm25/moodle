<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace core_reportbuilder\external;

use advanced_testcase;
use core_reportbuilder\manager;
use core_reportbuilder_generator;
use moodle_url;
use core_reportbuilder\local\helpers\user_filter_manager;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\output\report_action;
use core_user\reportbuilder\datasource\users;

/**
 * Unit tests for custom report exporter
 *
 * @package     core_reportbuilder
 * @covers      \core_reportbuilder\external\custom_report_exporter
 * @copyright   2022 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class custom_report_exporter_test extends advanced_testcase {

    /**
     * Test exported data structure when editing a report
     */
    public function test_export_editing(): void {
        global $PAGE;

        $this->resetAfterTest();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'My report', 'source' => users::class, 'default' => false]);

        $instance = manager::get_report_from_persistent($report);
        $instance->set_report_action(new report_action('Add', []));
        $instance->set_report_info_container('Hello');
        $instance->add_attributes(['data-foo' => 'bar', 'data-another' => '1']);

        $PAGE->set_url(new moodle_url('/'));

        $exporter = new custom_report_exporter($report, [], true);
        $export = $exporter->export($PAGE->get_renderer('core_reportbuilder'));

        $this->assertNotEmpty($export->table);
        $this->assertEquals('Hello', $export->infocontainer);
        $this->assertEquals(0, $export->filtersapplied);
        $this->assertFalse($export->filterspresent);
        $this->assertEmpty($export->filtersform);
        $this->assertTrue($export->editmode);
        $this->assertEmpty($export->attributes);

        // The following are all generated by additional exporters.
        $this->assertNotEmpty($export->sidebarmenucards);
        $this->assertNotEmpty($export->conditions);
        $this->assertNotEmpty($export->filters);
        $this->assertNotEmpty($export->sorting);
        $this->assertNotEmpty($export->cardview);

        // The following should not be present when editing.
        $this->assertObjectNotHasProperty('button', $export);
    }

    /**
     * Test exported data structure when viewing a report
     */
    public function test_export_viewing(): void {
        global $PAGE;

        $this->resetAfterTest();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'My report', 'source' => users::class, 'default' => false]);

        $instance = manager::get_report_from_persistent($report);
        $instance->set_report_action(new report_action('Add', []));
        $instance->set_report_info_container('Hello');
        $instance->add_attributes(['data-foo' => 'bar', 'data-another' => '1']);

        $PAGE->set_url(new moodle_url('/'));

        $exporter = new custom_report_exporter($report, ['pagesize' => 10], false);
        $export = $exporter->export($PAGE->get_renderer('core_reportbuilder'));

        $this->assertNotEmpty($export->table);
        $this->assertEquals('Hello', $export->infocontainer);
        $this->assertEquals(0, $export->filtersapplied);
        $this->assertFalse($export->filterspresent);
        $this->assertEmpty($export->filtersform);
        $this->assertFalse($export->editmode);
        $this->assertEquals([
            ['name' => 'data-foo', 'value' => 'bar'],
            ['name' => 'data-another', 'value' => '1']
        ], $export->attributes);

        // The following are all generated by additional exporters.
        $this->assertNotEmpty($export->button);

        // The following should not be present when viewing.
        $this->assertObjectNotHasProperty('sidebarmenucards', $export);
        $this->assertObjectNotHasProperty('conditions', $export);
        $this->assertObjectNotHasProperty('filters', $export);
        $this->assertObjectNotHasProperty('sorting', $export);
        $this->assertObjectNotHasProperty('cardview', $export);
    }

    /**
     * Test exported data structure when filters are present
     */
    public function test_export_filters_present(): void {
        global $PAGE;

        $this->resetAfterTest();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'My report', 'source' => users::class, 'default' => false]);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:email']);

        $PAGE->set_url(new moodle_url('/'));

        $exporter = new custom_report_exporter($report, ['pagesize' => 10], false);
        $export = $exporter->export($PAGE->get_renderer('core_reportbuilder'));

        $this->assertTrue($export->filterspresent);
        $this->assertNotEmpty($export->filtersform);
        $this->assertEquals(0, $export->filtersapplied);
    }

    /**
     * Test exported data structure when filters are applied
     */
    public function test_export_filters_applied(): void {
        global $PAGE;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report(['name' => 'My report', 'source' => users::class, 'default' => false]);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:email']);

        // Apply filter.
        user_filter_manager::set($report->get('id'), ['user:email_operator' => text::IS_NOT_EMPTY]);

        $PAGE->set_url(new moodle_url('/'));

        $exporter = new custom_report_exporter($report, ['pagesize' => 10], false);
        $export = $exporter->export($PAGE->get_renderer('core_reportbuilder'));

        $this->assertTrue($export->filterspresent);
        $this->assertNotEmpty($export->filtersform);
        $this->assertEquals(1, $export->filtersapplied);
    }
}
