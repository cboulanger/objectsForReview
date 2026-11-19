# Local test environment

A single Podman (or Docker) container with Apache, PHP 8.2, MariaDB and OJS 3.5, preinstalled with a test journal. The plugin directory is bind-mounted into the container, so changes to PHP files, templates and locale files are live without rebuilding.

For local testing only: the passwords are fixed defaults, mail is only logged, and the container listens on 127.0.0.1 only.

## Usage

```sh
dev/ojs-dev.sh up      # builds the image on first use, then starts OJS
dev/ojs-dev.sh logs    # follow Apache/PHP logs
dev/ojs-dev.sh shell   # shell in /var/www/html (e.g. to run mysql ojs)
dev/ojs-dev.sh down    # remove the container and all test data
dev/ojs-dev.sh reset   # start again from a fresh install
dev/ojs-dev.sh build   # rebuild the image (after changing files in dev/)
```

Open <http://localhost:8080/index.php/test>. Set `OJS_PORT` to use a different port.

| Account | Password | Roles |
| --- | --- | --- |
| `admin` | `ojsadmin` | Site admin, journal manager |
| `author` | `ojsauthor` | Author |

On first start, the container installs the plugin's database tables and enables the plugin in the test journal. Where to find it:

- Manager: Settings › Website › Objects for review
- Author: submission wizard, "For the Editors" step

Changes to the plugin's database schema (`ObjectsForReviewSchemaMigration.php`) or `version.xml` take effect only in a fresh container (`dev/ojs-dev.sh reset`).

## Podman on macOS

The Podman VM must be able to see the plugin directory. By default it shares only `/Users`, `/private` and `/var/folders`. If the code lives elsewhere (e.g. on another volume), recreate the machine with that path shared:

```sh
podman machine rm
podman machine init -v /Users:/Users -v /private:/private -v /var/folders:/var/folders -v /Volumes/Hub:/Volumes/Hub
podman machine start
```

## Files

- `Containerfile`: the image (OJS version in `ARG OJS_VERSION`)
- `build-install.sh`: runs at build time; creates the database, installs OJS, creates the journal and users
- `devSetup.php`: OJS CLI tool used by the scripts (install, journal, enable-plugin)
- `entrypoint.sh`: container start; sets `base_url`/`allowed_hosts`, installs the plugin, runs Apache
