{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     OpenClaw gateway and Control UI behind the panel's HTTPS proxy (RC-17). --}}
name: {{ project }}

services:
  openclaw:
    image: {{ image.app }}
    restart: unless-stopped
    mem_limit: {{ memory_limit }}
{{#if cpu_limit}}
    cpus: {{ cpu_limit }}
{{/if}}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    user: "0:0"
    environment:
      HOME: /home/node
      OPENCLAW_STATE_DIR: /data/.openclaw
      OPENCLAW_WORKSPACE_DIR: /data/workspace
      OPENCLAW_GATEWAY_PORT: "18789"
      OPENCLAW_GATEWAY_TOKEN: "{{ secret.OPENCLAW_GATEWAY_TOKEN }}"
      OPENCLAW_CONTROL_UI_ORIGIN: "{{ url }}"

    command:
      - /bin/sh
      - -ec
      - |
        mkdir -p /data/.openclaw /data/workspace
        find /data ! -user node -exec chown node:node {} +
        CONFIG=$$(node -e '
        const route = require("fs").readFileSync("/proc/net/route", "utf8").split("\n").slice(1)
          .map((line) => line.trim().split(/\s+/)).find((fields) => fields[1] === "00000000");
        if (!route) { console.error("no default route"); process.exit(1); }
        const proxy = route[2].match(/../g).reverse().map((byte) => parseInt(byte, 16)).join(".");
        console.log(JSON.stringify([
          { path: "gateway.mode", value: "local" },
          { path: "gateway.bind", value: "lan" },
          { path: "gateway.controlUi.allowedOrigins", value: [process.env.OPENCLAW_CONTROL_UI_ORIGIN] },
          { path: "gateway.trustedProxies", value: [proxy + "/32"] },
        ]));
        ')
        setpriv --reuid=node --regid=node --init-groups node dist/index.js config set --batch-json "$$CONFIG"
        exec setpriv --reuid=node --regid=node --init-groups node dist/index.js gateway --port 18789

    volumes:
      - {{ volume.data }}:/data

volumes:
  {{ volume.data }}:
    external: true
