# Assignment bulk edit (local_assignbulkedit)

Edit the settings of every assignment in a course on one page, instead of opening each assignment's settings one at a
time.

In a course, open **More → Bulk edit assignments**. Each assignment is one row of the table. Pick the columns you want
with **Columns** (grouped like the assignment settings form; remembered per user):

| Group | Settings |
|---|---|
| General | Assignment name (renames everywhere, incl. gradebook and calendar); visibility (needs `moodle/course:activityvisibility`) |
| Availability | Allow submissions from, due date, cut-off date, remind me to grade by (checked against each other as the settings form does) |
| Submission types | Online text, word limit, file submissions, maximum number of files, maximum submission size, accepted file types |
| Feedback types | Feedback comments, feedback files, annotate PDF, offline grading worksheet |
| Submission settings | Require students to click Submit, require submission statement, maximum attempts, additional attempts |
| Grade | Maximum grade; grade to pass; anonymous submissions; hide grader identity; marking workflow; marking allocation |

Submission and feedback types that are disabled on the site are left out. Some settings are locked as in the settings
form: "Require students to click Submit" and anonymous submissions once there are submissions, the maximum grade once
there are grades (or when the assignment uses a scale), and the marking workflow settings of assignments with
multiple markers (Moodle 5.3+).

- **Show changed assignments only** narrows the list to assignments with unsaved edits.
- **Save changes** first shows a summary of every change (old → new, per assignment), with warnings where a change has
  knock-on effects; nothing is saved until you confirm.
- **All settings (except name, visibility and dates)** in the toolbar copies one assignment's whole setup to the
  selected assignments.
- **Filter** by keyword (assignment or section name). Select all and Apply to selected only affect the assignments
  shown; the filter is kept after saving.
- Edit any cell, or tick assignments and use the toolbar to **Apply to selected** a value for one setting.
- Only the values you changed are saved, so the page never overwrites edits made elsewhere after you opened it.
- If any value is invalid, nothing is saved and the errors are shown in place.

## Extensions & overrides

**Bulk edit assignments → Extensions & overrides** works on chosen students and/or groups across all (or chosen)
assignments in the course:

- **Grant extensions**: to a date, or a number of hours/days/weeks after each student's due date. Choosing a group
  gives each of its students an extension. **Remove extensions** takes them away again.
- **Add or update overrides**: allow submissions from, due date and cut-off date (set, or add time to the assignment's
  own), time limit when assignment time limits are enabled on the site, and an optional reason. New values are merged
  into an existing override for the same student or group, so its other settings are kept. **Remove overrides** deletes
  them.
- **Preview** lists every student/group × assignment with the current and new values (and why any are skipped, e.g. a
  due date after the cut-off date, or an assignment with no due date to extend); nothing is saved until **Apply**.
- Extensions are saved as the assignment's own Grant extension page does (`mod/assign:grantextension`); overrides
  through mod_assign's override manager on Moodle 5.3+, or as its override page does on 5.2
  (`mod/assign:manageoverrides`). Everything is saved in one transaction. Without `moodle/site:accessallgroups` only
  your groups are offered.
- The page also lists every current extension and override in the course.

## How it saves

Settings are written straight to the `assign` table and to each submission/feedback plugin's own settings rather than
through `assign::update_instance()`. That function expects the full settings form: given a partial record it turns off
every submission and feedback type not mentioned and resets other settings. The plugin reproduces the side effects
instead: calendar events (including those of overrides), the gradebook, the "no submissions" flag, the course cache
and the `course_module_updated` log event.

## Permissions

Uses `moodle/course:manageactivities` (course and each assignment), plus the assignment capabilities above for
extensions and overrides. No capabilities of its own.

## Requirements

Moodle 5.2 – 5.3.

## License

GNU GPL v3 or later.
