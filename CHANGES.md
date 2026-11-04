# 0.2.0

* [*] The extension no longer replaces another custom DNS backend on install. If one is active, the settings page shows it and the administrator decides whether to replace it. Uninstalling disables the DNS backend only if it belongs to this extension.
* [*] Saving a DNS zone in Plesk never waits for the Don Dominio API: the change is queued and applied in the background.
* [*] NS delegations of subdomains are now synchronized. Records Don Dominio does not support (such as DS) are listed in the preview and on the Domains page instead of being skipped silently.
* [*] Records added in Don Dominio outside Plesk are kept and listed, instead of being deleted by the next synchronization.
* [*] The import from Don Dominio no longer deletes records that only exist in Plesk: it keeps and lists them, and lists the values it replaces.

# 0.1.0

* [+] First release, with a license agreement the administrator accepts before first use: replication of Plesk DNS zones to Don Dominio, initial import, per-domain activation, preview, safety threshold, backups and retries.
