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
 * Rows of the create assignments form: add, remove, and fill names from a pasted list.
 *
 * @module     local_assignbulkedit/create
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {watchFormById} from 'core_form/changechecker';

/**
 * Initialise the form.
 *
 * @param {string} formId
 */
export const init = (formId) => {
    const form = document.getElementById(formId);
    if (!form) {
        return;
    }
    watchFormById(formId);
    const body = form.querySelector('[data-region="rows"]');
    const rows = () => [...body.querySelectorAll('[data-region="row"]')];
    let next = rows().length;

    // Once anything changes, the preview no longer matches the form: preview again before creating.
    const preview = form.querySelector('[data-region="preview"]');
    const stalePreview = () => {
        if (preview) {
            preview.hidden = true;
        }
    };
    form.addEventListener('input', stalePreview);
    form.addEventListener('change', stalePreview);

    // A copy of the last row, empty except for its section.
    const addRow = (name = '') => {
        const last = rows()[rows().length - 1];
        const row = last.cloneNode(true);
        const index = next++;
        row.querySelectorAll('[name], [id], [for]').forEach((element) => {
            ['name', 'id', 'for'].forEach((attribute) => {
                const value = element.getAttribute(attribute);
                if (value) {
                    element.setAttribute(attribute, value.replace(/^r\[\d+\]/, `r[${index}]`).replace(/_r\d+_/, `_r${index}_`));
                }
            });
        });
        row.querySelectorAll('input').forEach((input) => {
            input.value = '';
            input.classList.remove('is-invalid');
        });
        row.querySelectorAll('.is-invalid').forEach((element) => element.classList.remove('is-invalid'));
        row.querySelectorAll('.invalid-feedback').forEach((element) => element.remove());
        row.querySelector('[data-field="name"]').value = name;
        body.appendChild(row);
        stalePreview();
        return row;
    };

    form.addEventListener('click', (e) => {
        if (e.target.closest('[data-action="addrow"]')) {
            addRow().querySelector('[data-field="name"]').focus();
        } else if (e.target.closest('[data-action="removerow"]')) {
            const row = e.target.closest('[data-region="row"]');
            if (rows().length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input').forEach((input) => {
                    input.value = '';
                });
            }
            stalePreview();
        } else if (e.target.closest('[data-action="paste"]')) {
            const paste = form.querySelector('[data-region="paste"]');
            const names = paste.value.split(/\r?\n/).map((name) => name.trim()).filter((name) => name.length);
            // Fill the empty rows first, then add more.
            const empty = rows().map((row) => row.querySelector('[data-field="name"]')).filter((input) => input.value === '');
            names.forEach((name) => {
                const input = empty.shift();
                if (input) {
                    input.value = name;
                } else {
                    addRow(name);
                }
            });
            paste.value = '';
            stalePreview();
        }
    });

    // Enter in a box shouldn't submit the form.
    form.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
            e.preventDefault();
        }
    });
};
