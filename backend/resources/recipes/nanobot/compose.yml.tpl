{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     nanobot gateway and WebUI on the official Python image; the pinned PyPI release installs into its own volume (RC-34). --}}
name: {{ project }}

services:
  nanobot:
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
      HOME: /home/nanobot
      PATH: /home/nanobot/.venv/bin:/usr/local/bin:/usr/local/sbin:/usr/sbin:/usr/bin:/sbin:/bin
      PYTHONUNBUFFERED: "1"
      PIP_DISABLE_PIP_VERSION_CHECK: "1"
      NANOBOT_PACKAGE: nanobot-ai==0.3.5
      NANOBOT_WEB_TOKEN: "{{ secret.NANOBOT_WEB_TOKEN }}"
      NANOBOT_PUBLIC_URL: "{{ url }}"

    command:
      - /bin/sh
      - -ec
      - |
        id -u nanobot >/dev/null 2>&1 || useradd -M -d /home/nanobot -u 1000 -s /bin/sh nanobot
        chown nanobot:nanobot /home/nanobot
        find /home/nanobot/.nanobot /home/nanobot/.venv ! -user nanobot -exec chown -h nanobot:nanobot {} +
        as_nanobot() { setpriv --reuid=nanobot --regid=nanobot --init-groups "$$@"; }
        WANT="$$NANOBOT_PACKAGE python-$$(python3 -c 'import platform; print(platform.python_version())')"
        if [ "$$(cat /home/nanobot/.venv/.panel-installed 2>/dev/null)" = "$$WANT" ]; then
          echo "[panel] $$WANT already installed, skipping pip"
        else
          echo "[panel] installing $$WANT"
          find /home/nanobot/.venv -mindepth 1 -delete
          as_nanobot python3 -m venv /home/nanobot/.venv
          as_nanobot /home/nanobot/.venv/bin/pip install --no-cache-dir "$$NANOBOT_PACKAGE"
          as_nanobot sh -c 'printf "%s\n" "$$1" > /home/nanobot/.venv/.panel-installed' sh "$$WANT"
          echo "[panel] installed $$WANT"
        fi
        as_nanobot python3 - <<'PY'
        import json, os, pathlib
        path = pathlib.Path("/home/nanobot/.nanobot/config.json")
        first = not path.exists()
        config = {} if first else json.loads(path.read_text())
        if first:
            config["tools"] = dict(restrictToWorkspace=True, webuiAllowRemotePackageInstall=False, my=dict(allowSet=False))
        host = os.environ["NANOBOT_PUBLIC_URL"].split("://", 1)[-1].rstrip("/")
        scheme = "wss" if os.environ["NANOBOT_PUBLIC_URL"].startswith("https://") else "ws"
        config.setdefault("gateway", dict()).update(host="127.0.0.1", port=18790)
        config.setdefault("channels", dict()).setdefault("websocket", dict()).update(
            enabled=True, host="0.0.0.0", port=8765, path="/", publicWsUrl=scheme + "://" + host + "/",
            tokenIssueSecret="$${NANOBOT_WEB_TOKEN}", websocketRequiresToken=True,
        )
        tmp = path.with_name("config.json.panel-tmp")
        tmp.write_text(json.dumps(config, indent=2) + "\n")
        os.replace(tmp, path)
        print("[panel] config.json", "created" if first else "kept; panel keys refreshed")
        PY
        exec setpriv --reuid=nanobot --regid=nanobot --init-groups nanobot gateway

    healthcheck:
      test: ["CMD", "python3", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8765/', timeout=5)"]
      interval: 15s
      timeout: 10s
      retries: 5
      start_period: 1200s

    volumes:
      - {{ volume.data }}:/home/nanobot/.nanobot
      - {{ volume.venv }}:/home/nanobot/.venv

volumes:
  {{ volume.data }}:
    external: true
  {{ volume.venv }}:
    external: true
