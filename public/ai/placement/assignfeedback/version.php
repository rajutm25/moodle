<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin version and other meta-data are defined here.
 *
 * @package     aiplacement_assignfeedback
 * @copyright   2025 Raju Thummoji  <rajutummoji@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'aiplacement_assignfeedback';
$plugin->release = '1.0';
$plugin->version = 2025101900; // Use current timestamp version.
$plugin->requires = 2025040800; // Requires Moodle 5.0 or later.
$plugin->maturity = MATURITY_BETA;

// Plugin dependencies
$plugin->dependencies = [
    'mod_assign' => ANY_VERSION, // Require mod_assign of any version
];
