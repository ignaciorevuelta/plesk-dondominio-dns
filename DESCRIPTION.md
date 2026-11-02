Manage your DNS records in Plesk and have them published by [Don Dominio](https://www.dondominio.com/).

- Edit zones in the usual Plesk DNS screen; every change is replicated to Don Dominio through its official API.
- When you first save your API credentials, the existing Don Dominio zones are copied into Plesk, so nothing published is lost.
- Each domain is synchronized only after you enable it, and you can preview the pending changes first.
- A safety threshold holds any change that would delete or replace a large part of a zone until you confirm it.
- A backup of the zone is saved before every write. Failed writes are retried automatically.

**Requirements**

- A Don Dominio account with API access, and this server's IP address authorized for it.
- Each domain must have a DNS service in Don Dominio and use the Don Dominio name servers.

**Limitations**

- NS and SOA records are not synchronized: Don Dominio keeps its own.
- Plesk does not send per-record TTLs, so TTLs are not replicated.
- Plesk allows only one custom DNS backend, so this extension cannot be used together with other DNS provider extensions.

This is a free, open-source extension. It is not affiliated with Don Dominio.
