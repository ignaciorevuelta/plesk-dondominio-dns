# Verificación en servidor (2026-11-02)

Plesk Obsidian 18.0.82.1 sobre Ubuntu 26.04.

## Estado previo

- `server_dns --info` no muestra backend personalizado, y en la tabla `misc` no hay ningún parámetro de backend.
- No hay extensiones de DNS instaladas (Route 53, Slave DNS, Cloudflare).

## JSON que recibe el backend

Capturado con un script temporal que copiaba stdin a un fichero, activado con `server_dns --enable-custom-backend <ruta>` y desactivado justo después.

- Al activar el backend, Plesk lo llama una vez con `[]`. El script debe aceptarlo y salir con 0.
- Al añadir o borrar un registro, Plesk envía **la zona completa**, no solo el cambio: `[{"command":"update","zone":{...}}]`.
- `zone` tiene `name` (con punto final, p. ej. `aossasport.com.`), `displayName`, `soa` y `rr`.
- `rr` es una lista. Cada registro tiene `host`, `displayHost`, `type`, `displayValue`, `opt`, `value` y `ttl`.
- `ttl` llega **vacío**: se confirma que no hay TTL por registro.
- `host` y los valores de nombre llevan punto final.
- Forma de `opt`:
  - MX: la prioridad, p. ej. `10`.
  - SRV: `prioridad peso puerto`, p. ej. `0 0 993`, con el destino en `value`.
  - TXT, A, CNAME y NS: vacío.
- La zona incluye NS y SOA, que la extensión debe ignorar.
- No se comprobó CAA, porque no había ninguno en la zona capturada.

## Escritura en Don Dominio (servicio `redir`)

Probado en `eforma4.net`, servicio `Redirect & Parking`, con un TXT de prueba `_prueba-api.eforma4.net`:

| Llamada | Resultado |
|---|---|
| `service/dnscreate` | `success:true`. `responseData.dns` devuelve el registro creado con su `entityID`. |
| `service/dnsupdate` | `success:true`. El valor cambió de `prueba-1` a `prueba-2`. |
| `service/dnsdelete` | `success:true`. El registro desaparece. |

La zona tenía 12 registros antes y 12 después. El registro nuevo recibe `ttl` `-` y `priority` `-` si no se envían.
