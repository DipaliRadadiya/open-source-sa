{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     CyberChef: static build served by unprivileged nginx on 8080 (RC-31). --}}
name: {{ project }}

services:
  cyberchef:
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
