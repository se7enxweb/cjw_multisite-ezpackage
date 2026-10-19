# cjw_multisite-ezpackage

The source of **CJW Multisite**, the starter site package of the
[CJW Themes Jumper](https://github.com/se7enxweb/cjw_themes_jumper) design (design `starter`) for Exponential: one
folder per package, unpacked. The setup wizard, the kickstarter and `exp:install` download the built archives
(`.ezpkg`) from the package server,
[exponential.packages.exponential.earth](https://exponential.packages.exponential.earth/exponential/6.0/6.0.15/index.xml);
this repository is where they are made.

The packages are built and tested for **Exponential 6.0.15**.

## What is in it

| Package | Type | What it brings |
|---|---|---|
| `cjw_multisite` | site | The site package: an English and a German site with the administration (`admin`, `editor`). Its installer (`settings/cjw-multi-site-installer.php`, `cjw-starter-install.php` and the settings scripts) writes the siteaccesses, the active extensions, roles and the post-install fixes, and imports the starter content |
| `cjw_multisite_democontent` | contentobject | The starter content in German and English with its content classes, plus side files the installer reads: `cjw-class-additions.xml`, `cjw-explayouts.json` (layouts), `cjw-paths.json` (URL paths) and `cjw-starter-import.json` |

Each folder has a `package.xml` (name, version, dependencies, install items), a `documents/LICENCE` and the files
the package installs.

### What it needs

| Needed | Where it comes from |
|---|---|
| `sevenx_classes`, the base content classes | The package server; its source is [se7enxweb/sevenx-ezpackage](https://github.com/se7enxweb/sevenx-ezpackage) |
| `cjw_multisite_democontent` | This repository |
| The extension `cjw_themes_jumper` in `extension/` | [se7enxweb/cjw_themes_jumper](https://github.com/se7enxweb/cjw_themes_jumper); Exponential 6.0.15 requires it in its `composer.json`. It brings the design and the **images and files** of the starter content (`share/content-images`, `share/content-files`), which are in no package |

Without the extension the install logs the missing files and the site has no design.

## Installing

You do not need this repository to install the site. Choose CJW Multisite in the setup wizard, or install it from
the command line. Most of the starter content is German, so choose German too:

```bash
./console exp:install --package=cjw_multisite --languages=eng-US,ger-DE
```

The help pages of an Exponential installation describe every way: `doc/help/site-packages/cjw-multisite/`.

## Building

```bash
cd packages
./build.sh
```

`build.sh` packs every folder of `packages/` into `<folder>.ezpkg` next to it, the same script as in the 7x
package repository. It leaves out version control folders and files (`.git`, `.gitignore`, `.svn` ...), editor and
system leftovers (`.DS_Store`, `.idea`, `*~`, `*.swp`), backup copies (`*.bak`, `*.bak.*`) and `node_modules`. The
built archives are not committed here.

`bin/pack.sh --output-dir=<dir>` does the same into another folder (it also leaves out `.cache`), and
`ant/createzpkg.sh` packs a single folder.

To publish a new build, copy the changed `.ezpkg` files into `exponential/6.0/6.0.15/` of
[se7enxweb/exponential.packages.exponential.earth](https://github.com/se7enxweb/exponential.packages.exponential.earth)
and commit them there. Test a package by importing and installing it in a throwaway installation first.

## Changing a package

- Keep the `md5sum` attributes in `package.xml` equal to the files they name.
- The version of each package is in its `package.xml` (`<number>6.0</number>`, `<release>15-stable</release>`). The
  package server's `index.xml` lists `cjw_multisite` with the same text (`version="6.0-15-stable"`), so the setup
  wizard sees an imported copy as current.
- Commit subjects start with `Added:`, `Updated:`, `Removed:` or `Renamed:`.

## License

GNU General Public License, version 2 or (at your option) any later version. The full text is in
[LICENSE](LICENSE); both packages state the same in their `package.xml` (`<licence>GPL</licence>`) and in
`documents/LICENCE`. The `cjw_themes_jumper` extension, with the starter content's images and files, is under the
GNU GPL version 2 as well.

## Problems and contributions

Report problems in the issues of this repository. Pull requests are welcome; how to submit a site package of your
own to the package server is described in the
[contributing guide of the package server](https://github.com/se7enxweb/exponential.packages.exponential.earth/blob/master/CONTRIBUTING.md).
