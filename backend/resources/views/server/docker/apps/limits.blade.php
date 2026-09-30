{{-- The protections every service gets, in one place.

     These are not per-app choices, and that is why they are a partial rather
     than copied into each template. The generic `compose.blade.php` carries all
     three with a paragraph each explaining why; an app template that forgot one
     would lose the protection silently, and the app that forgot it would be the
     one that took the box down.

     - **A memory ceiling, always.** One container with no limit can exhaust the
       machine and take the panel with it.
     - **A CPU quota, only when one was chosen.** `$cpus` is optional and the key
       is omitted when it is null — deliberately, and it is the one asymmetry in
       this file. Defaulting it would cap every container site already on the box
       the next time it deployed; and rendering `cpus:` with an empty value is
       worse than omitting it, because Compose reads that as `0`, which means *no
       limit* and would quietly undo a limit set anywhere else.
     - **Bounded logs.** Docker's default json-file driver has NO max size, so a
       chatty container fills the disk and the first symptom is every site on the
       box failing to write.
     - `restart: unless-stopped` so a container that dies comes back, and a
       container the panel stopped stays stopped.

     **Indent the `@include`, not this file's first line.** `View::render()`
     strips leading whitespace from a partial, so wherever the first line lands
     is decided by the caller — `    @include(...)` gives it its four spaces, and
     every line after it carries its own. Getting this wrong puts `restart:` at
     column zero and the file stops being valid YAML. --}}
    restart: unless-stopped
    mem_limit: {{ $limit }}
@if (($cpus ?? null) !== null)
    cpus: {{ $cpus }}
@endif
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"
