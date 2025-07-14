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
 * Forgot password form handling.
 *
 * @module     core/forgotpassword
 * @copyright  2025 Raju Thummoji <raju.tummoji@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const init = () => {
    const usernameField = document.querySelector('input[name="username"]');
    const emailField = document.querySelector('input[name="email"]');
    const usernameSubmit = document.querySelector('input[name="submitbuttonusername"]');
    const emailSubmit = document.querySelector('input[name="submitbuttonemail"]');

    if (!usernameField || !emailField) {
        return;
    }

    usernameField.addEventListener('input', () => {
        if (usernameField.value.trim() !== '') {
            emailField.value = '';
        }
    });

    emailField.addEventListener('input', () => {
        if (emailField.value.trim() !== '') {
            usernameField.value = '';
        }
    });

    if (usernameSubmit) {
        usernameSubmit.addEventListener('click', () => {
            emailField.value = '';
        });
    }

    if (emailSubmit) {
        emailSubmit.addEventListener('click', () => {
            usernameField.value = '';
        });
    }
};
