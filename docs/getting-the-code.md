# Getting the code

Gather's code lives in a Git repository on GitHub:

```
https://github.com/YOUR-ACCOUNT/gather
```

(Replace `YOUR-ACCOUNT` with the real account name once the repository is
published.)

There are three ways to get it. All three give you the same program. Pick the
one that suits your hosting and how comfortable you are with the command line.

| Way | Best for | Updating later |
|---|---|---|
| A. Release package (`.zip` or `.tar.gz`) | Shared hosting, people who prefer a file manager | Upload the new package and extract it over the old files |
| B. GitHub "Download ZIP" | Trying it out without Git | The same as A |
| C. `git clone` | VPS, or cPanel's Git Version Control | `git pull` |

## A. A release package

A release package contains only what the server needs: no tests, developer
tools or documentation. Download it from the repository's **Releases** page
(`https://github.com/YOUR-ACCOUNT/gather/releases`), or build it yourself (see
[Building a package yourself](#building-a-package-yourself)).

There are three versions of each release:

| File | Contents | Use it when |
|---|---|---|
| `gather-<date>-<commit>.zip` | One folder, `gather/`, holding everything | You extract it in your home folder and point a (sub)domain at `gather/public` |
| `gather-<date>-<commit>-flat.zip` | The same files with no top folder | You extract straight into an existing folder such as `public_html` (the [cPanel fallback](install-cpanel.md#fallback-when-the-document-root-cannot-be-changed)) |
| `gather-<date>-<commit>.tar.gz` | The same as the first `.zip` | You work on a server over SSH |

Every package has a `VERSION` file at the top that records the exact commit and
build time.

## B. GitHub "Download ZIP"

On the repository page, click the green **Code** button, then **Download
ZIP**. You get `gather-main.zip`, which holds a folder called `gather-main/`.
Rename the folder to `gather` if you like; the name does not matter.

This ZIP also contains the `docs/`, `tests/` and `dev-tools/` folders. They are
harmless on a server (they are never served to visitors), but a release
package is smaller.

## C. Clone with Git

```bash
git clone https://github.com/YOUR-ACCOUNT/gather.git gather
```

The clone is ready to run: the folders Gather writes to (`storage/…`,
`public/uploads/`) already exist, and everything that holds your own data is
listed in `.gitignore`, so Git never touches it.

On cPanel you can do the same thing from the browser with **Git Version
Control** (see [Install on cPanel](install-cpanel.md#option-b-git-version-control)).

## What is in the folder

```
gather/
├── public/           the only folder visitors can reach (the "document root")
│   ├── index.php     every page is served by this one file
│   ├── assets/       styles, scripts, icons and fonts
│   └── uploads/      resized images (written by Gather)
├── src/, templates/, themes/, migrations/, vendor/   the program itself
├── bin/              command-line tools (cron, migrate, backup, restore…)
├── storage/          private files written by Gather (originals, logs, backups…)
├── config.example.php
├── nginx.conf.example
└── .htaccess         fallback for hosts where the document root cannot be public/
```

`config.php` does not exist yet. The installer creates it.

## Updating later

Updating keeps all your content, members and settings. They live in the
database, in `config.php`, in `storage/` and in `public/uploads/`, and an
update never replaces any of those.

1. **Make a backup first**: Admin → **Backups** → tick **Include uploaded
   files** → **Back up to the server now**. Or use your host's backup tool.
   See [Backups and restore](manager-guide/backups.md).
2. **Put the new code in place**, in the same way you installed it:
   - Package: upload the new package to the same place and extract it over
     the old files, letting it overwrite them. With the folder version
     (`gather/`), extract it in the folder *above* your Gather folder, so its
     files land on top of the old ones. On cPanel, see
     [Updating](install-cpanel.md#updating).
   - Git: `cd` into the folder and run `git pull`. On cPanel: Git Version
     Control → **Manage** → **Pull or Deploy** → **Update from Remote**.
3. **Update the database**: run `php bin/migrate` in the Gather folder (on a
   VPS, as the web server user; see your install guide). It applies any new
   database changes and is safe to run when there are none. No terminal? See
   [Running a command without a terminal](install-cpanel.md#running-a-command-without-a-terminal).
4. **Check**: Admin → **System health**. "Migrations" must say *none
   pending*, and nothing should be marked "Problem".

If your host uses OPcache with timestamp checks switched off, reload PHP
(or ask your host to) after updating.

### What you must never overwrite or delete

| Path | What it holds |
|---|---|
| `config.php` | Database details and the secret key (`app_key`). Without the same key, stored SMTP passwords cannot be read and signed links stop working. |
| `storage/` | Original uploads, private image sizes, backups, logs, bounce messages. |
| `public/uploads/` | The resized images visitors see. |
| `.user.ini` or `php.ini` in the document root | PHP settings your host or control panel wrote (for example upload limits). |

Neither the packages nor the Git repository contain any of these, so a normal
update leaves them alone. The danger is deleting the whole folder and
uploading a fresh copy: **never do that** on a live site.

One more thing to watch: some control panels write PHP settings as
`php_value` lines into the `.htaccess` file of the document root (that is
`public/.htaccess`). An update replaces that file, so check your upload
limits on **System health** after updating and set them again if needed.

Files that were removed from Gather in a new version stay behind after you
extract a package over the old one. They do no harm.

## Building a package yourself

For developers working from a clone (needs Git, Bash and `zip`):

```bash
PHP=/path/to/php bin/check     # lint, tests and smoke test (needs tests/config.test.php)
bin/package                    # dist/gather-<date>-<commit>.tar.gz
bin/package --zip              # ... plus dist/gather-<date>-<commit>.zip
bin/package --zip --flat       # ... plus a zip without the top folder
bin/package v1.2.0             # build from a tag or commit instead of HEAD
```

`bin/package` archives the **committed** code only (commit first), leaves out
`config.php`, everything under `storage/` and `public/uploads/`, tests,
developer tools and `docs/`, and refuses to finish if a private file slipped
in. It keeps macOS metadata (`._*` files) out of the archives.
