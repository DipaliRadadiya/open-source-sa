
{{-- `external: true` is load-bearing: the panel creates this volume so it appears
     on the Docker page, can be attributed to this database, and is guarded by the
     same delete checks a site's volume is. Declared without it, Compose would make
     its own and the panel would neither see it nor protect it.

     The comment sits ABOVE `volumes:` rather than inside the block: Blade strips
     the leading whitespace of the line after a comment, so a comment placed before
     an indented line flattens it. --}}
volumes:
  {{ $volume }}:
    external: true
@if ($network !== null)

networks:
  {{ $network }}:
    external: true
@endif
