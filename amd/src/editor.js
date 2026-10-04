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
 * Bulk-fill toolbar, select-all and change highlighting for the assignment bulk edit table.
 *
 * @module     local_assignbulkedit/editor
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString, getStrings} from 'core/str';
import {add as addToast} from 'core/toast';
import {watchFormById, markFormChangedFromNode} from 'core_form/changechecker';
import Ajax from 'core/ajax';
import Notification from 'core/notification';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';

/**
 * Highlight a cell whose value differs from what was loaded.
 *
 * @param {HTMLInputElement|HTMLSelectElement} input
 */
const refreshChanged = (input) => {
    input.closest('td').classList.toggle('table-warning', input.value !== input.dataset.original);
};

/**
 * Set an input's value as if the user typed it.
 *
 * @param {HTMLInputElement|HTMLSelectElement} input
 * @param {string} value
 */
const setValue = (input, value) => {
    input.value = value;
    input.classList.remove('is-invalid');
    refreshChanged(input);
    markFormChangedFromNode(input);
};

/**
 * Escape text for use in HTML.
 *
 * @param {string} text
 * @returns {string}
 */
const escapeHtml = (text) => {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
};

/**
 * Initialise the page.
 *
 * @param {string} formId
 */
export const init = (formId) => {
    const form = document.getElementById(formId);
    if (!form) {
        return;
    }
    watchFormById(formId);

    const fieldSelect = form.querySelector('[data-action="bulkfield"]');
    const valueLabel = form.querySelector('[data-region="bulkvaluelabel"]');
    const valueControls = [...form.querySelectorAll('[data-bulkvalue]')];
    const valueControl = () => valueControls.find((control) => control.dataset.bulkvalue === fieldSelect.value);
    const selectAll = form.querySelector('[data-action="selectall"]');
    // Only assignments left visible by the filter can be selected or changed in bulk.
    const rowBoxes = () => [...form.querySelectorAll('[data-action="select"]')].filter((box) => !box.closest('tr').hidden);
    const selectedRows = () => rowBoxes().filter((box) => box.checked).map((box) => box.closest('tr'));

    const syncToolbar = () => {
        const active = valueControl();
        valueControls.forEach((control) => {
            control.hidden = control !== active;
        });
        valueLabel.htmlFor = active.id;
    };
    fieldSelect.addEventListener('change', syncToolbar);
    syncToolbar();

    const requireSelection = async() => {
        const rows = selectedRows();
        if (!rows.length) {
            addToast(await getString('selectfirst', 'local_assignbulkedit'), {type: 'warning'});
        }
        return rows;
    };

    form.querySelector('[data-action="bulkapply"]').addEventListener('click', async() => {
        const field = fieldSelect.value;
        const value = valueControl().value;
        (await requireSelection()).forEach((row) => {
            if (field === 'all') {
                copyAll(row, value);
                return;
            }
            const control = row.querySelector(`[data-field="${field}"]`);
            // E.g. a file size limit this course doesn't offer.
            const allowed = control.tagName !== 'SELECT' || [...control.options].some((option) => option.value === value);
            if (!control.disabled && allowed) {
                setValue(control, value);
            }
        });
    });

    const assignRows = [...form.querySelectorAll('tr[data-assignid]')];
    const assignRow = (assignid) => form.querySelector(`tr[data-assignid="${assignid}"]`);

    const filterInput = document.querySelector('[data-action="filter"]');
    const filterValue = form.querySelector('[data-region="filtervalue"]');
    const filterCount = document.querySelector('[data-region="filtercount"]');
    const changedOnly = document.querySelector('[data-action="changedonly"]');
    const rowChanged = (row) => [...row.querySelectorAll('[data-original]')]
        .some((input) => input.value !== input.dataset.original);
    const applyFilter = async() => {
        const words = filterInput.value.toLowerCase().split(/\s+/).filter((word) => word.length);
        let shown = 0;
        assignRows.forEach((row) => {
            const text = row.dataset.search.toLowerCase();
            row.hidden = !words.every((word) => text.includes(word)) || (changedOnly.checked && !rowChanged(row));
            shown += row.hidden ? 0 : 1;
        });
        filterValue.value = filterInput.value;
        filterCount.textContent = words.length || changedOnly.checked ?
            await getString('filtercount', 'local_assignbulkedit', {shown, total: assignRows.length}) : '';
        const boxes = rowBoxes();
        selectAll.checked = boxes.length > 0 && boxes.every((box) => box.checked);
        selectAll.indeterminate = !selectAll.checked && boxes.some((box) => box.checked);
    };
    filterInput.addEventListener('input', applyFilter);
    changedOnly.addEventListener('change', applyFilter);
    filterInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
        }
    });
    applyFilter();

    // Columns picker: show or hide columns, and remember the choice for next time.
    const columnsMenu = document.querySelector('[data-region="columns"]');
    const columnBoxes = [...columnsMenu.querySelectorAll('[data-column]')];
    const applyColumns = () => {
        const shown = columnBoxes.filter((box) => box.checked).map((box) => box.dataset.column);
        form.querySelectorAll('[data-col]').forEach((cell) => {
            cell.hidden = !shown.includes(cell.dataset.col);
        });
        // The bulk toolbar only offers the columns on show.
        [...fieldSelect.options].forEach((option) => {
            option.hidden = option.value !== 'all' && !shown.includes(option.value);
        });
        if (fieldSelect.selectedOptions[0]?.hidden) {
            const first = [...fieldSelect.options].find((option) => !option.hidden);
            if (first) {
                fieldSelect.value = first.value;
            }
            syncToolbar();
        }
        return shown;
    };
    // Uses the AJAX web service rather than the routed REST API, which needs web server rewrite rules.
    const saveColumns = (shown) => Ajax.call([{
        methodname: 'core_user_set_user_preferences',
        args: {preferences: [{name: 'local_assignbulkedit_columns', value: shown.join(',')}]},
    }])[0].catch(Notification.exception);
    columnsMenu.addEventListener('change', (e) => {
        if (e.target.dataset.column) {
            saveColumns(applyColumns());
        }
    });
    columnsMenu.querySelector('[data-action="defaultcolumns"]').addEventListener('click', () => {
        const defaults = columnsMenu.dataset.default.split(',');
        columnBoxes.forEach((box) => {
            box.checked = defaults.includes(box.dataset.column);
        });
        applyColumns();
        // An empty preference means "the defaults", so later default changes reach this user too.
        saveColumns([]);
    });

    selectAll.addEventListener('change', () => {
        rowBoxes().forEach((box) => {
            box.checked = selectAll.checked;
        });
    });
    form.addEventListener('change', (e) => {
        if (e.target.dataset.action === 'select') {
            const boxes = rowBoxes();
            selectAll.checked = boxes.every((box) => box.checked);
            selectAll.indeterminate = !selectAll.checked && boxes.some((box) => box.checked);
        }
    });

    form.addEventListener('input', (e) => {
        if (e.target.dataset.original !== undefined) {
            refreshChanged(e.target);
        }
    });

    // Enter in a toolbar value box should apply, not submit the whole form.
    valueControls.forEach((control) => control.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            form.querySelector('[data-action="bulkapply"]').click();
        }
    }));

    // Copy every setting of another assignment, except its name, visibility and dates.
    const NOT_COPIED = ['name', 'visible', 'allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'gradingduedate'];
    const copyAll = (row, sourceId) => {
        const source = assignRow(sourceId);
        if (!source || source === row) {
            return;
        }
        source.querySelectorAll('[data-field][data-original]').forEach((sourceControl) => {
            const field = sourceControl.dataset.field;
            const control = row.querySelector(`[data-field="${field}"]`);
            if (NOT_COPIED.includes(field) || !control || control.disabled) {
                return;
            }
            const value = sourceControl.value;
            if (control.tagName !== 'SELECT' || [...control.options].some((option) => option.value === value)) {
                setValue(control, value);
            }
        });
    };

    // Review before saving: list every change, and save only once confirmed.
    const columnLabel = (field) => form.querySelector(`th[data-col="${field}"]`)?.dataset.label ?? field;
    const shownValue = (control, value, none) => {
        if (control.tagName === 'SELECT') {
            const option = [...control.options].find((o) => o.value === value);
            return option ? option.textContent.trim() : value;
        }
        return value === '' ? none : value.replace('T', ' ');
    };
    let confirmed = false;
    form.addEventListener('submit', async(e) => {
        if (confirmed) {
            return;
        }
        e.preventDefault();
        const strings = await getStrings([
            'confirmtitle', 'confirmsave', 'confirmnone', 'confirmtypeoff', 'confirmdates', 'nochanges',
        ].map((key) => ({key, component: 'local_assignbulkedit'})));
        const [title, save, none, typeoffwarning, dateswarning, nochanges] = strings;

        let count = 0;
        let assignments = 0;
        const warnings = new Set();
        let list = '';
        assignRows.forEach((row) => {
            const changed = [...row.querySelectorAll('[data-original]')]
                .filter((control) => !control.disabled && control.value !== control.dataset.original);
            if (!changed.length) {
                return;
            }
            assignments++;
            let items = '';
            changed.forEach((control) => {
                const field = control.dataset.field;
                items += `<li>${escapeHtml(columnLabel(field))}: ` +
                    `<del>${escapeHtml(shownValue(control, control.dataset.original, none))}</del> → ` +
                    `<strong>${escapeHtml(shownValue(control, control.value, none))}</strong></li>`;
                count++;
                if (['onlinetext', 'file'].includes(field) && control.value === '0') {
                    warnings.add(typeoffwarning);
                }
                if (['duedate', 'cutoffdate'].includes(field)) {
                    warnings.add(dateswarning);
                }
            });
            const name = row.querySelector('.local-assignbulkedit-sticky2 a').textContent.trim();
            list += `<li><strong>${escapeHtml(name)}</strong><ul>${items}</ul></li>`;
        });

        if (!count) {
            addToast(nochanges, {type: 'info'});
            return;
        }
        const summary = await getString('confirmsummary', 'local_assignbulkedit', {settings: count, assignments});
        const body = `<p>${escapeHtml(summary)}</p><ul class="mb-2">${list}</ul>` +
            [...warnings].map((warning) => `<div class="alert alert-warning py-2 mb-2">${escapeHtml(warning)}</div>`).join('');
        const modal = await ModalSaveCancel.create({title, body, buttons: {save}, show: true, removeOnClose: true});
        modal.getRoot().on(ModalEvents.save, () => {
            confirmed = true;
            form.requestSubmit();
        });
    });
};
