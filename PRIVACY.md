# Privacy

This extension runs entirely on your Plesk server. Its author does not receive any data from it.

- **What it sends, and where:** your Don Dominio API credentials and the DNS records of the domains you enable are sent only to the Don Dominio API (`https://simple-api.dondominio.net/`). This is needed to read and update your zones there.
- **What it stores on your server:** the API user, the API password (encrypted with Plesk's settings storage), your settings, and JSON backups of the zones in the extension's `var` directory. The last 20 backups per domain are kept.
- **Removal:** uninstalling the extension disables the DNS backend and its scheduled task. Plesk removes the extension's settings and files.
