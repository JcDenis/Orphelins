# README

[![Release](https://img.shields.io/github/v/release/JcDenis/Orphelins?color=lightblue)](https://github.com/JcDenis/Orphelins/releases)
![Date](https://img.shields.io/github/release-date/JcDenis/Orphelins?color=red)
[![Dotclear](https://img.shields.io/badge/dotclear-v2.40-137bbb.svg)](https://dotclear.org/telechargement)
[![License](https://img.shields.io/github/license/JcDenis/Orphelins?color=white)](https://github.com/JcDenis/Orphelins/blob/master/LICENSE)

## ABOUT

_Orphelins_ is a plugin for the open-source web publishing software called [Dotclear](https://www.dotclear.org).

> Find dead links, missing files and unused media of the blog

It adds a report to the blog administration, made by a check of all the entries of the blog:

* **links** which do not answer anymore (the page is gone, the site does not exist…),
* **files** used by an entry (images, attachments…) which are not in the media library anymore,
* **media** of the media library no entry uses.

Nothing is fixed nor deleted automatically, and nothing is shown on the public side of the blog.

With the plugin [ClaudetteMcp](https://github.com/JcDenis/ClaudetteMcp), an AI assistant connected to the blog can read the report.

**This is an early version.** See [CONCEPTION.md](https://github.com/JcDenis/Orphelins/blob/master/CONCEPTION.md) (in French) for the design.

A user guide with screenshots is available in French: [guide d'utilisation](https://github.com/JcDenis/Orphelins/blob/master/docs/fr/utilisation.md).

## REQUIREMENTS

* Dotclear 2.40
* PHP 8.3+
* The permission to manage all the entries of the blog ("manage all entries and comments")
* A server allowed to open connections to other web sites, to check links

## USAGE

1. Install and enable the plugin.
2. In the blog menu, open _Dead links and unused media_.
3. Start a check with the button _New check_ and keep the page open until the end.

A check reads the entries of the blog (all of them, or the ones published since a date) whatever their type (posts, pages…) and their status. It goes on by steps of a few seconds,
so it does not depend on the time limit of the server. It can be stopped: what was checked so far is kept.

The result is in two lists, on two pages: _Links and files_, with a link to the entry to edit, and _Unused media_.
Each list shows what is to fix, can be filtered (type, status, result of the check, text) and exported as a CSV file.
Each line has a status:

| Status | Meaning |
|---|---|
| Problem | Dead link, missing file or unused media |
| To verify | The site gave no clear answer: it refuses robots, asks to log in, is too slow or in error |
| Without problem | The link answers, the file exists |
| Not checked | Link to a domain not to check or to a local address, or line waiting for the next check |
| Ignored | You asked not to hear about this line anymore |

Selected lines can be checked again (their entries are read again, so a fixed entry loses its problems), ignored for good,
or forgotten: a forgotten line leaves the lists until a next check finds it again.
Selected unused media can also be deleted from the media library, after a confirmation.

Options of the check:

* a date, to only check the entries published since that date (all the entries by default),
* domains not to check (sites which always refuse robots),
* number of days a link without problem is kept before being asked again to its site (7 by default).

The check is also a task of the plugin _Maintenance_ (tab _Servicing_), which can remind you to do it again from time to time.

On the dashboard, the favorite _Dead links and unused media_ is an icon giving the number of problems to fix.

### Limits

* An unused media is a media no entry uses. It is also looked for in the metadata of the entries, in the descriptions
  of the categories and of the blogs and in the settings (themes, widgets), and other plugins can tell the media they use
  (see below), but a theme file or another plugin may still use it: look at it before deleting it, and ignore the ones you keep.
* Links are checked from the server of the blog: local and private addresses are never requested.
* Only links and media of the entries are checked, not those of the comments.

### For other plugins

A plugin which uses media in its own data can tell it with the behavior `OrphelinsUsedMedia`, so these media are never listed as unused.
Its argument is an `ArrayObject` to append to: a media ID (`int`), a path from the root of the media library or an address (`string`).
It is called in backend context, for the current blog, once per step of the check and before deleting media. For example, in `Prepend.php`:

```php
App::behavior()->addBehavior('OrphelinsUsedMedia', function (ArrayObject $media): void {
    $media->append(12);                                 // media ID
    $media->append('images/logo.png');                  // path in the media library
    $media->append('https://example.com/public/a.jpg'); // address
});
```

Media linked to an entry by the core table `post_media` (attachments, featured media of the plugin featuredMedia…) are already known.

## AI ASSISTANT

If the plugin [ClaudetteMcp](https://github.com/JcDenis/ClaudetteMcp) is enabled on the blog, _Orphelins_ adds this tool to its connector:

| Tool | Description |
|---|---|
| `list_content_problems` | Read the result of the last check: summary and lines, by type, status or search |

The assistant acts with the permissions of the user of the key: this user must manage all the entries of the blog.
The tool only reads: the assistant does not start a check, ignores nothing and deletes no media.

ClaudetteMcp is not required. After installing _Orphelins_, reconnect the connector of the assistant to validate the new tool.

## LINKS

* [License](https://github.com/JcDenis/Orphelins/blob/master/LICENSE)
* [User guide (in French)](https://github.com/JcDenis/Orphelins/blob/master/docs/fr/utilisation.md)
* [Packages & details](https://github.com/JcDenis/Orphelins/releases)
* [Issues & security](https://github.com/JcDenis/Orphelins/issues)

## CONTRIBUTORS

* Jean-Christian Denis (author)
* Claude (AI assistant by Anthropic)

You are welcome to contribute to this code.
