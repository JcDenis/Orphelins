# Orphelins

Notes for AI assistants working on the Dotclear plugin Orphelins.

**Read first** the shared conventions of the author's Dotclear modules:
https://raw.githubusercontent.com/JcDenis/gh2da/master/CONVENTIONS.md
(if a web tool refuses the address, use `curl -s` on it from the shell).
The rules below take precedence for this module.

## Module

Report of dead links, missing files and unused media of a blog, made by a check of its entries (menu Blog),
with a read only tool for an AI assistant connected through ClaudetteMcp.
Nothing on the public side: no URL, no widget, no template tag.

Required Dotclear and PHP versions: see `_define.php`.

## Structure

* `src/Items.php`: all database work on table `orphelins` (one row per link or file of an entry, one row per unused media).
  Its methods work on the current blog and **do not check permissions**: callers must do it. `src/Item.php`: a row (read only object).
* `src/Checker.php`: the check, done by steps of `BUDGET` seconds in three phases (entries, links, media).
  Its progress is a JSON blog setting (`state`); options and last check date are blog settings too (namespace `Orphelins`).
* `src/Url.php`: addresses found in HTML, full addresses, files of the media library and their thumbnails.
* `src/Http.php`: check of a link with the core `HttpClient`; refuses local and private addresses, follows redirections itself.
* `src/Install.php`: table structure. A change of the table needs a migration there.
* `src/My.php`: no permission of its own, `My::isAllowed()` is the core permission `contentadmin` on the current blog.
* `src/Manage.php`: two lists on two pages linked by pseudo tabs, links and files (default) and unused media (`part=media`),
  each with its filters, its display options (`Orphelins` and `OrphelinsMedia`) and its actions on selected lines.
  `ManageCheck.php` (options and start; check going on, with request `run` set by the redirection after each step;
  check left before its end, which waits for "resume" or "give up"), `ManageVars.php` (request values, names of the results); `js/manage.js`.
  `Export.php`: a list as a CSV file (request `export`), all the lines matching the filters.
* `src/BackendBehaviors.php`: filters options (`adminFiltersListsV3`), dashboard favorite with the number of problems,
  task of the plugin maintenance (`dcMaintenanceInit`, `src/Maintenance/CheckTask.php`).
* `src/ClaudetteMcp/ListContentProblems.php`: tool `list_content_problems`, registered in `src/Frontend.php`
  with the behavior `ClaudetteMcpAddTool`.

## Pitfalls

* A step must stay short: the page (or the maintenance task) calls `Checker::step()` again until it returns true.
  Never do the whole check in one request.
* A step lasts seconds and the user may stop the check meanwhile: `Checker::step()` reads the state again in the database
  (`stored()`) before saving, and gives up if the check was stopped or replaced (`started` is the identity of a check).
* A blog setting saved with `put()` is not read again by the same page load: `Checker` keeps its state in memory.
* `Http::check()` is a server side request to an address written by any author: keep the refusal of local and private
  addresses (the host of the blog is the only exception), and never let `HttpClient` follow redirections by itself.
* Option `since_date`: only entries published since that date (`post_dt`) are read, and `Checker::start()` deletes the rows
  of older entries. Unused media stay right because `Checker::isUsedElsewhere()` reads all the entries, whatever their date:
  never limit it by date.
* Statuses: `IGNORED` is a choice of the user and survives the checks; `SKIPPED` (ignored domain, local address) is evaluated
  again by each check. `Items::linkResult()` never changes an ignored row.
* Never count on foreign keys to delete rows: MySQL tables with the MyISAM engine (old blogs) have none.
  `Items::deleteStrayed()` (start of a check) and `Items::deleteOfMedia()` (media deleted from the page) do it.
* Action "forget" deletes rows (`Items::delete()`), nothing else: the next check finds them again. Not to be confused with `IGNORED`.
* Links with the same address share their check (`item_hash`), one request per address.
* Unused media: a media is used if it is linked to an entry (`post_media`), found in a content by the check (itself or a thumbnail,
  `Url::mediaKey()`), or found by `LIKE` in entries of all blogs, categories and blogs descriptions and settings.
  When in doubt a media is "used": a false unused media may end deleted.
* Media are only deleted from the admin page, on selected lines, after a new look for each one, by `App::media()->removeFile()`
  which checks the media permissions. No automatic deletion, no deletion by the MCP tool.
* The column "Result" is one or two words in `nowrap` (`Manage::info()`), the sentence is its title (`Manage::infoDetail()`).
  The result is a key made of several fields (`Item::result()`), named by `ManageVars::resultName()`: the filter by result
  is done in PHP (`Items::match()`), and a new result must be added to `ManageVars::results()`.
* CSV export: values come from the authors of the blog, keep the protection against formulas in `Export::cell()`.
* A translation of the module replaces the one of the core for the same English text, everywhere in the admin:
  before adding a text, look for it in the core `locales/fr/*.po` and use another English text if the meaning differs.
* Messages with a number use the plural form of `__()` with two different English texts and `%d` in both
  (same text twice gives the singular for any number; in French 0 takes the singular).
* A check finished on the page of the plugin is logged for the plugin maintenance (`ManageCheck`, `CheckTask::ID`), if it is there.
* Text search is done in PHP (`Items::match()`), not in SQL: same result on MySQL, PostgreSQL and SQLite.
* MCP tool: one read only tool, permission checked in `available()`, names and descriptions in English, not translated.
* `Frontend::init()` must not be limited to some URL types, or the tool is never registered.

## Testing

* Extra admin pages tested by CI are listed in `.github/dotclear-test.txt`.
* Local site: entries with links to a second local server answering 200, 404, 403, 500, redirections and a slow page
  (the host of the blog is allowed, whatever the port), files present and missing in `public/`, media rows used and unused.
* Check a whole check (three phases), a second one (kept and ignored lines), the actions on selected lines,
  the task of the plugin maintenance, the favorite, in English and French.
* Tool: through a ClaudetteMcp connector on a test blog (the connector must be reconnected to validate a new tool).

## Design

Read `CONCEPTION.md` (in French) before changing the design, the data model, the permissions or the tool,
and update it with the change.
