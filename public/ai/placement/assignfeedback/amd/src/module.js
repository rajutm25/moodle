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

/**
 * Module initialization
 *
 * @module     aiplacement_assignfeedback/module
 * @copyright  2025 Raju Thummoji <rajutummoji@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import {call as fetchMany} from 'core/ajax';
import Notification from 'core/notification';

/**
 * Initialize the module
 *
 * @param {number} courseId The course ID
 * @param {Object} capabilities The user capabilities
 */
export const init = (courseId, capabilities) => {
    // Create context menu element
    const menuHtml = `
        <div class="assignfeedback-menu">
            <div class="list-group">
                ${capabilities.feedback ?
                    `<a href="#" class="list-group-item list-group-item-action" data-action="feedback">
                        <i class="fa fa-comment-dots"></i>  ${M.util.get_string('feedback', 'aiplacement_assignfeedback')}
                    </a>` : ''}
                ${capabilities.grade ?
                    `<a href="#" class="list-group-item list-group-item-action" data-action="grade">
                        <i class="fa fa-check-circle"></i>  ${M.util.get_string('grade', 'aiplacement_assignfeedback')}
                    </a>` : ''}
                ${capabilities.contentdetector ?
                    `<a href="#" class="list-group-item list-group-item-action" data-action="contentdetector">
                        <i class="fa fa-robot"></i>  ${M.util.get_string('contentdetector', 'aiplacement_assignfeedback')}
                    </a>` : ''}
            </div>
            <div class="ai-icon-bottom-right">
        <div class="ai-icon-bottom-right"> ${M.util.get_string('poweredby', 'aiplacement_assignfeedback')}
        <i class="fa fa-robot fa-spin"></i>
        </div>
        </div>
        </div>`;

    // Create result tooltip element
    const tooltipHtml = `
        <div class="assignfeedback-tooltip" style="display:none;">
            <div class="assignfeedback-tooltip-content"></div>
            <button type="button" class="btn btn-link btn-sm close-tooltip">
                <i class="fa fa-times"></i>
            </button>
        </div>`;
    // Wait until the submission text container exists
    const waitForContainer = () => new Promise((resolve) => {
        const check = () => {
            const $container = $('.assignsubmission_onlinetext');
            if ($container.length) {
                resolve($container);
            } else {
                setTimeout(check, 100); // check every 100ms
            }
        };
        check();
    });

    waitForContainer().then(($container) => {
        const $menu = $(menuHtml).appendTo($container);
        const $tooltip = $(tooltipHtml).appendTo($container);
        // Add styles
        $('head').append(`
            <style>
                .assignfeedback-tooltip {
                    position: absolute;
                    max-width: 400px;
                    background: #fff;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    padding: 15px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                    z-index: 1050;
                }
                .assignfeedback-tooltip .close-tooltip {
                    position: absolute;
                    top: 5px;
                    right: 5px;
                    padding: 0;
                    width: 24px;
                    height: 24px;
                    line-height: 24px;
                    text-align: center;
                }
                .assignfeedback-menu {
                    position: absolute;
                    background: #fff;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                    z-index: 1000;
                    bottom: 5%;
                    right: 5%;
                }
                .assignfeedback-loading {
                    text-align: center;
                    padding: 10px;
                }
                .ai-icon-bottom-right {
                position: relative;
                left: 5px;   
                color: #007bff;  
                opacity: 0.7;    
            }
            </style>
        `);
        // Process text through GPT
        const processText = async (text, action) => {
            try {
                const result = await fetchMany([{
                    methodname: 'aiplacement_assignfeedback_process_text',
                    args: {
                        text: text,
                        action: action,
                        courseid: courseId
                    }
                }])[0];
                return result.result;
            } catch (error) {
                Notification.exception(error);
                return M.util.get_string('error', 'aiplacement_assignfeedback');
            }
        };
        // Function to get full submission text
        const getSubmissionText = () => {
            const text = $('.assignsubmission_onlinetext').text().trim();
            return text || '[No submission text found]';
        };

        // Handle menu item clicks
        $menu.on('click', '[data-action]', async function(e) {
            e.preventDefault();
            const action = $(this).data('action');
            const text = getSubmissionText();

            // Show loading tooltip
            $tooltip.find('.assignfeedback-tooltip-content')
                .html('<div class="assignfeedback-loading"><i class="fa fa-spinner fa-spin"></i> Processing...</div>');
            $tooltip.show();

            // Position tooltip at bottom of the submission container
            const offset = $('.assignsubmission_onlinetext').offset();
            $tooltip.css({
                top: offset.top + $('.assignsubmission_onlinetext').outerHeight() + 10,
                left: offset.left
            });

            // Process text
            const result = await processText(text, action);

            // Update tooltip with result
            $tooltip.find('.assignfeedback-tooltip-content').html(result);
        });

        // Handle tooltip close button
        $tooltip.on('click', '.close-tooltip', () => {
            $tooltip.hide();
        });
    });
};