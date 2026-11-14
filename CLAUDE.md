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
* `src/Manage.php` (list, filters, actions on selected lines), `ManageCheck.php` (start and follow a check, options),
  `ManageVars.php` (filters and display options); `js/manage.js`.
* `src/BackendBehaviors.php`: filters options (`adminFiltersListsV3`), dashboard favorite with the number of problems,
  task of the plugin maintenance (`dcMaintenanceInit`, `src/Maintenance/CheckTask.php`).
* `src/ClaudetteMcp/ListContentProblems.php`: tool `list_content_problems`, registered in `src/Frontend.php`
  with the behavior `ClaudetteMcpAddTool`.

## Pitfalls

* A step must stay short: the page (or the maintenance task) calls `Checker::step()` again until it returns true.
  Never do the whole check in one request.
* A blog setting saved with `put()` is not read again by the same page load: `Checker` keeps its state in memory.
* `Http::check()` is a server side request to an address written by any author: keep the refusal of local and private
  addresses (the host of the blog is the only exception), and never let `HttpClient` follow redirections by itself.
* Statuses: `IGNORED` is a choice of the user and survives the checks; `SKIPPED` (ignored domain, local address) is evaluated
  again by each check. `Items::linkResult()` never changes an ignored row.
* Links with the same address share their check (`item_hash`), one request per address.
* Unused media: a media is used if it is linked to an entry (`post_media`), found in a content by the check (itself or a thumbnail,
  `Url::mediaKey()`), or found by `LIKE` in entries of all blogs, categories and blogs descriptions and settings.
  When in doubt a media is "used": a false unused media may end deleted.
* Media are only deleted from the admin page, on selected lines, after a new look for each one, by `App::media()->removeFile()`
  which checks the media permissions. No automatic deletion, no deletion by the MCP tool.
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
