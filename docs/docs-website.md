# Publishing the documentation as a website

The guides in `docs/` can be turned into a small, stand-alone website: plain
HTML pages with a contents list, the screenshots and working links between
the guides. Use it to read the installation guides before Gather is installed,
or to give your volunteers a link to the manager guide.

Once Gather is running you do not need this: every guide is already in the
admin area under **Help centre** (see [The help centre](manager-guide/help-centre.md)),
and the member guide is on your site at `/help`.

## Build the site

On any computer with PHP 8.2 or newer and a copy of the code:

```bash
php bin/docs-html
```

The command writes the site to `dist/docs-site/`:

| File | What it is |
|---|---|
| `index.html` | The start page (this documentation's README) |
| `manager-guide/…` | One page per manager guide, with `index.html` as its contents |
| `member-guide.html`, `install-cpanel.html`, … | The other guides |
| `images/` | The screenshots |
| `docs.css` | The stylesheet |
| `.nojekyll` | Tells GitHub Pages to publish the files exactly as they are |

All links are relative, so the folder works anywhere: open `index.html`
straight from your computer, upload the folder to any web host, or publish it
on GitHub Pages. No database, PHP or JavaScript is needed to view it.

Run the command again after the guides change. It empties `dist/docs-site/`
first, so pages that were removed disappear too.

## Publish on GitHub Pages

GitHub Pages hosts static websites for free from a GitHub repository. Pick
one of these two ways.

### Option 1: a `gh-pages` branch (no automation)

1. Build the site: `php bin/docs-html`.
2. Put the contents of `dist/docs-site/` in a branch called `gh-pages`. From
   a clone of your repository:

   ```bash
   php bin/docs-html
   git worktree add --orphan -b gh-pages ../gather-pages   # first time only (Git 2.42+)
   cp -R dist/docs-site/. ../gather-pages/
   cd ../gather-pages
   git add --all
   git commit -m "Publish documentation"
   git push origin gh-pages
   ```

   With an older Git, create the branch once with
   `git switch --orphan gh-pages` in a separate clone instead.
3. On GitHub, open the repository's **Settings → Pages**. Under **Build and
   deployment**, choose **Source: Deploy from a branch**, branch **gh-pages**,
   folder **/ (root)**, and **Save**.
4. After a minute the site is at
   `https://YOUR-ACCOUNT.github.io/YOUR-REPOSITORY/`.

To update it later, run steps 1 and 2 again (in `../gather-pages`: remove
the old files with `git rm -r --quiet .` before copying the new ones).

### Option 2: GitHub Actions (rebuilt on every push)

1. On GitHub, open **Settings → Pages** and choose **Source: GitHub Actions**.
2. Add a file `.github/workflows/docs.yml` to your repository with:

   ```yaml
   name: Documentation website
   on:
     push:
       branches: [main]
       paths: ['docs/**', 'bin/docs-html', 'src/Core/Markdown.php', 'src/lib/help.php', 'templates/help/**']
     workflow_dispatch:
   permissions:
     contents: read
     pages: write
     id-token: write
   concurrency:
     group: pages
     cancel-in-progress: true
   jobs:
     deploy:
       runs-on: ubuntu-latest
       environment:
         name: github-pages
         url: ${{ steps.deployment.outputs.page_url }}
       steps:
         - uses: actions/checkout@v4
         - uses: shivammathur/setup-php@v2
           with:
             php-version: '8.3'
         - run: php bin/docs-html
         - uses: actions/upload-pages-artifact@v3
           with:
             path: dist/docs-site
         - id: deployment
           uses: actions/deploy-pages@v4
   ```

3. Commit and push. The **Actions** tab shows the build; the address appears
   under **Settings → Pages** when it has finished.

Gather does not ship this workflow file switched on, because it only works
after Pages has been set to **GitHub Actions** in your repository.

## Other hosts

Upload the contents of `dist/docs-site/` to any web space (a subdomain such
as `docs.example.org`, or a folder of an existing site) with FTP or your
host's file manager. There is nothing to configure.

## What is and is not included

- Every guide listed in the [documentation start page](README.md), in the
  same order as in the admin help centre.
- Links to files outside `docs/` (such as `nginx.conf.example`) are shown as
  plain text, because those files are not part of the website. They are in
  the code you downloaded.
- The static site has no search box; use your browser's find (Ctrl+F or ⌘F)
  on a page, or the search in the admin help centre.
