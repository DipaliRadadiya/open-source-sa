{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Hermes Agent gateway and web dashboard, supervised together by the image's s6, behind the panel's HTTPS proxy (RC-44). --}}
name: {{ project }}

services:
  hermes:
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

    environment:
      HERMES_DASHBOARD: "1"
      HERMES_DASHBOARD_PORT: "9119"
      HERMES_DASHBOARD_BASIC_AUTH_USERNAME: admin
      HERMES_DASHBOARD_BASIC_AUTH_PASSWORD: "{{ secret.HERMES_DASHBOARD_BASIC_AUTH_PASSWORD }}"
      HERMES_DASHBOARD_BASIC_AUTH_SECRET: "{{ secret.HERMES_DASHBOARD_BASIC_AUTH_SECRET }}"
      HERMES_DASHBOARD_PUBLIC_URL: "{{ url }}"

    command:
      - /bin/sh
      - -ec
      - |
        PROXY=$$(python3 -c '
        import socket, struct
        for line in open("/proc/net/route").read().splitlines()[1:]:
            fields = line.split()
            if fields[1] == "00000000":
                print(socket.inet_ntoa(struct.pack("<L", int(fields[2], 16))) + "/32")
                break
        else:
            raise SystemExit("no default route")
        ')
        if python3 - "$$PROXY" <<'PY'
        import sys, yaml
        try:
            config = yaml.safe_load(open("/opt/data/config.yaml")) or {}
        except OSError:
            config = {}
        sys.exit(0 if (config.get("dashboard") or {}).get("trusted_proxies") == [sys.argv[1]] else 1)
        PY
        then
          echo "[panel] dashboard.trusted_proxies already $$PROXY"
        else
          hermes config set dashboard.trusted_proxies "[\"$$PROXY\"]"
          /command/s6-svc -r /run/service/dashboard
          echo "[panel] dashboard.trusted_proxies set to $$PROXY; dashboard restarted to read it"
        fi
        exec hermes gateway run

    healthcheck:
      test: ["CMD", "python3", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:9119/api/status', timeout=5)"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 300s

    volumes:
      - {{ volume.data }}:/opt/data

volumes:
  {{ volume.data }}:
    external: true
