# Don Dominio DNS for Plesk

Plesk extension that replicates the DNS zones you edit in Plesk to [Don Dominio](https://www.dondominio.com/) through the Don Dominio API. Plesk is the source of truth for each domain you enable.

## Requirements

- Plesk Obsidian on Linux. Tested on Plesk Obsidian 18.0.82 on Ubuntu 26.04; `meta.xml` declares 18.0.40 as the minimum, which has not been tested.
- A Don Dominio account with API access. Don Dominio support provides the API user and password.
- This server's IP address authorized for the API in your Don Dominio account.
- For each domain: a DNS service in Don Dominio (any service listed by `service/list`, including *Redirect & Parking*) and the Don Dominio name servers.

## Install

Download the ZIP from the releases page and install it from **Extensions > My Extensions > Upload Extension**, or from the command line:

```bash
plesk bin extension --install dondominio-dns.zip
```

Installing registers the extension as Plesk's custom DNS backend only if no other one is active. Plesk allows only one: if another DNS provider extension is installed, the settings page shows it and lets you choose whether to replace it. Uninstalling disables the backend only if it is this extension's. Nothing is sent to Don Dominio until you enable a domain.

## How it works

1. **Settings:** enter the API credentials. The first time they are saved, every domain that exists in both Plesk and Don Dominio receives the Don Dominio records in its Plesk zone, which are the ones currently published. Records that only exist in Plesk are kept and listed, and Plesk values replaced by Don Dominio ones are listed too, after a backup. Domains in only one of the two are listed and left untouched.
2. **Domains:** preview the changes for a domain, then enable it. From then on, each change saved in Plesk is applied to Don Dominio as individual record changes.

Safeguards:

- The zone's own NS and SOA records are never synchronized: Don Dominio keeps its own. NS delegations of subdomains are synchronized.
- Records Don Dominio does not support (such as DS) are not synchronized and are listed in the preview and on the Domains page.
- Records added in Don Dominio outside Plesk are kept and listed, not deleted.
- A change that deletes or replaces more than the safety threshold (30% by default) of a zone is held until you confirm it.
- The zone is backed up before every write (last 20 per domain, in `/usr/local/psa/var/modules/dondominio-dns/backups/`).
- Deleting a domain or zone in Plesk never deletes anything in Don Dominio.
- If Don Dominio is unreachable, the change is retried up to 5 times with increasing delays.
- Saving a zone in Plesk never waits more than 10 seconds for a synchronization in progress; the change is applied by the scheduled task instead.

Limitations: TTLs are not replicated, because Plesk does not send per-record TTLs. CAA records are supported but have not been tested against a real zone yet.

## Development

```bash
composer install
vendor/bin/phpunit
```

The logic in `plib/library` (record normalization, zone diff, API client, sync engine, importer) is plain PHP with unit tests. `PleskDns`, `Config`, `Runner`, the forms and the controller use the Plesk SDK and are tested on a Plesk server.

Build the package:

```bash
git archive -o dondominio-dns.zip HEAD meta.xml DESCRIPTION.md CHANGES.md _meta htdocs plib
```

## License

MIT. The administrator accepts the [license agreement](plib/resources/EULA.md) in the extension before it does anything. Not affiliated with Don Dominio or Plesk.
