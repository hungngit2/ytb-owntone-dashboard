# Stereo (L/R) split across two AirPlay speakers via PipeWire

## Memory constraints (read this before changing anything)

chainedbox has **under 1GB of total RAM** and runs Home Assistant (~270MB),
Jellyfin (~175MB), AdGuard Home, Docker, and OwnTone all on the same box.
Its swap is `zram` (compressed RAM used as swap — it doesn't add real
capacity, it trades CPU for a little headroom) and sits at **90-99%
used even at idle**, before this feature does anything. There is
essentially no memory margin on this host.

During development this surfaced as four full host freezes requiring a
physical power-cycle, each needing a hard reset because the freeze
happens before `journald` can flush anything to persistent storage (no
forensic logs survive — `Storage=volatile` was the default; it's now
`Storage=persistent`, which will at least help if this happens again).
The freezes correlated with — but weren't conclusively proven to be
solely caused by — this feature's added load: PipeWire+WirePlumber's own
footprint is small in isolation (~20MB combined, confirmed via `ps`), but
on a host already at 90%+ swap, that plus transient `yt-dlp`/`ffmpeg`
bursts is enough to tip things over. **Design accordingly: assume there
is no spare memory, and prefer "runs only when actively needed" over
"runs 24/7 for convenience" for anything touching this host.** That's
why PipeWire/WirePlumber are started on-demand by a lightweight watcher
rather than running as always-on system services — see "Final
architecture" below.


OwnTone streams the same full stereo mix to every selected AirPlay output —
it has no concept of a "stereo pair" (left channel to one speaker, right to
another). This adds that without touching this app or OwnTone's own
playback/queue/control machinery: OwnTone keeps doing exactly what it does
today (pipe reading, queue-daemon, auto-advance, seek, metadata,
`/api/player`), and a small persistent PipeWire link keeps its own ALSA
output split across the two speakers via PipeWire's native RAOP client
support.

**Status: deployed and working on chainedbox.** Deployable elsewhere via
[docs/pipewire-stereo-split/install.sh](pipewire-stereo-split/install.sh).
Turning the feature on/off is done from OwnTone's own UI (or
`/api/outputs`) — see "Control surface" below, including a real
reliability caveat on that toggle.

## Final architecture (what's actually running)

```
ytb-stereo-split-watcher.sh (always running, ~5s poll of /api/outputs)
   |
   | starts/stops pipewire.service + wireplumber.service +
   | ytb-stereo-split-linker.service based on whether
   | "PipeWire - Stereo Pair" is selected
   v
OwnTone (unmodified) — ALSA output writes to hw:Loopback,0
   |                    (only when that output is selected)
   v
hw:Loopback,1  (captured by PipeWire as node "aloop-capture",
                only while PipeWire is running)
   |
   +--- capture_FL --> Phicomm R1 - Main  (RAOP sink, both L+R inputs)
   +--- capture_FR --> Phicomm R1 - Sub   (RAOP sink, both L+R inputs)
```

`ytb-stereo-split-linker.sh` itself re-asserts the two links every 5s
while running — self-healing if a speaker joins the network late or
reconnects after going offline. But it, and PipeWire/WirePlumber
underneath it, are **not** always-on system services — `install.sh`
installs but does not enable them at boot. Only `ytb-stereo-split-watcher.sh`
runs 24/7 (enabled, `WantedBy=multi-user.target`), and it's deliberately
lightweight: a plain `curl` to OwnTone's own API + a tiny `python3` JSON
parse, no PipeWire client libraries loaded, so the steady-state cost of
"is this feature available at all" stays close to zero on a host that
can't spare much (see "Memory constraints" above). When the toggle
flips on, the watcher starts `pipewire.service` → `wireplumber.service`
→ `ytb-stereo-split-linker.service` (staggered with a 2s gap each); when
it flips off, it stops them in reverse order.

`libpipewire-module-raop-discover` makes the two AirPlay speakers appear
as ordinary PipeWire sink nodes (via the same Avahi/mDNS this host already
runs), so no separate loopback/bridge device is needed to reach them.

## Why this shape (a two-step journey, not the first design)

**Attempt 1 — direct ALSA loopback bridge.** The original plan routed
OwnTone's own ALSA output through `snd-aloop` into PipeWire. The bridge
itself worked (`speaker-test`/`arecord` round-tripped real audio through
it cleanly), but OwnTone's ALSA *writer* never engaged the device:
`fuser` showed nothing ever opened the PCM node, even mid-playback. Two
root causes, found later: Docker was masking `/proc/asound` from the
container (breaking OwnTone's card-index lookup) and the loopback card
genuinely has no volume mixer element (breaking `mixer`/`mixer_device`
resolution) — see the gotchas below. At the time neither was diagnosed,
so the approach pivoted.

**Attempt 2 — pull OwnTone's independent MP3 stream instead.** Since
`http://127.0.0.1:3689/stream.mp3` was verified to carry real, correct
audio independent of the ALSA output, a small `ffmpeg` process pulled
that stream and fed PipeWire directly, split by channel. This worked for
the audio path itself, but caused a real, reproducible regression:
having a *second* client subscribe to the stream while OwnTone's own ALSA
output was also active caused OwnTone's player to intermittently
self-pause (visible in its logs: `Source is not providing sufficient
data, temporarily suspending playback` immediately after the new
subscriber registered, followed by `The ALSA device 'PipeWire - Stereo
Pair' stopped` and a `pause` state that didn't recover on its own). Two
concurrent consumers of the same underlying decode session isn't
something OwnTone's player handles cleanly.

**Final shape — fix the original ALSA path instead of working around
it.** Once `/proc/asound` masking and the missing mixer element were
actually root-caused and fixed (see gotchas), OwnTone's own ALSA writer
started working correctly — confirmed by capturing real, non-silent audio
from `hw:Loopback,1` directly, no `ffmpeg`/stream-pulling involved. This
is strictly simpler (no MP3 encode/decode round-trip, no second stream
consumer, no separate process to start/stop) and doesn't have the
self-pause regression. `ytb-stereo-split.sh`/`ytb-stereo-split-controller.sh`
(the `ffmpeg`-pulling version) were removed in favor of
`ytb-stereo-split-linker.sh`.

## Control surface: OwnTone's "PipeWire - Stereo Pair" output toggle

The dashboard already has an ALSA output entry named **"PipeWire - Stereo
Pair"** in OwnTone's output list (`id: "0"` in `/api/outputs`). Rather
than adding a new UI control, its `selected` on/off state directly gates
whether OwnTone writes real audio to the loopback — toggle it from
OwnTone's own web UI/Remote, or:
```
curl -X PUT http://127.0.0.1:3689/api/outputs/0 \
  -H 'Content-Type: application/json' -d '{"selected":true}'   # on
curl -X PUT http://127.0.0.1:3689/api/outputs/0 \
  -H 'Content-Type: application/json' -d '{"selected":false}'  # off
```

**Known reliability caveat — the toggle itself can fail intermittently.**
OwnTone's output-select endpoint hard-fails (HTTP 400) if its ALSA mixer
attach fails, and this loopback card has no real hardware mixer — worked
around with a synthetic ALSA `softvol` control (see gotchas). But that
softvol control's *existence* is apparently tied to whether something
currently has the underlying PCM device open — confirmed via `amixer -c 0
scontrols` returning empty when nothing was using the device, even though
it had shown the control moments earlier. OwnTone's own select-then-
attach-mixer sequence effectively races this: sometimes the control
exists in time, sometimes it doesn't, and the failure is not something
this deployment resolved (would need OwnTone source access to fix
properly — e.g. skip mixer attach when none of the standard elements
exist, rather than failing the whole selection). **In practice: retry
the toggle once or twice if it doesn't seem to take.** Once selection
does succeed, the actual audio path is solid (verified over 90+ seconds
of continuous stable playback with no self-pausing, direct
non-silent-audio capture from the loopback, and links persisting).

## Files on chainedbox

See [docs/pipewire-stereo-split/README.md](pipewire-stereo-split/README.md)
for the full file list and a one-click `install.sh` for deploying this to
another host. Summary:

- `/usr/local/bin/ytb-stereo-split-watcher.sh` +
  `/etc/systemd/system/ytb-stereo-split-watcher.service` — the only
  always-on piece (enabled at boot); starts/stops everything else based
  on the OwnTone toggle.
- `/usr/local/bin/ytb-stereo-split-linker.sh` +
  `/etc/systemd/system/ytb-stereo-split-linker.service` — the linker
  (see architecture above). **Not** enabled at boot; started/stopped by
  the watcher.
- `/etc/systemd/system/pipewire.service` / `wireplumber.service` —
  system-wide PipeWire (not the default per-user session — see below).
  **Not** enabled at boot; started/stopped by the watcher.
- `/etc/pipewire/pipewire.conf.d/30-raop-discover.conf` — RAOP discovery.
- `/etc/pipewire/pipewire.conf.d/10-aloop-capture.conf` — the
  `aloop-capture` node the linker connects from (actively required now,
  not a leftover).
- `/etc/wireplumber/main.lua.d/51-disable-aloop-autoprofile.lua` —
  excludes the Loopback card from WirePlumber's own automatic profile
  management so it never competes with OwnTone's direct access to it.
- `/mnt/appsrv/docker/owntone/config/asound.conf` (bind-mounted to
  `/etc/asound.conf` in the container) — the softvol mixer wrapper.
- `/opt/docker/owntone/config/owntone.conf` — `audio {}` block: `type =
  "alsa"`, `card = "stereo_split_out"`, `mixer = "Stereo Split"`,
  `mixer_device = "stereo_split_out"`, `nickname = "PipeWire - Stereo
  Pair"`.
- OwnTone's `docker-compose.yml` — `privileged: true` (see gotchas) plus
  `devices: ["/dev/snd:/dev/snd"]` and the `asound.conf` bind mount.

Dead-end leftover, harmless: `/etc/pipewire/pipewire.conf.d/20-stream-bridge.conf`
(a `null-audio-sink` from the middle attempt — its monitor ports turned
out not to be fed by its playback input at all, see gotchas). Nothing
routes through it.

## System-wide PipeWire

This host is headless (root-only Docker, no desktop session), and
PipeWire's shipped unit files refuse to run as root
(`ConditionUser=!root`). Solved with a dedicated system user (`pipewire`,
in the `audio` group) and custom system-level units:
- `pipewire.service` runs directly (no socket-activation — a systemd
  `.socket` unit pre-binding the same path conflicted with PipeWire's own
  socket creation and silently produced no working socket at all; simpler
  to let PipeWire create its own).
- `wireplumber.service` needs `HOME`/`XDG_*` env vars pointed at a real
  writable directory (`/var/lib/pipewire`, since the `pipewire` user has
  no home dir), and wraps its `ExecStart` in `dbus-run-session`
  (WirePlumber wants a D-Bus session bus, which doesn't exist headless —
  `dbus-run-session` spins up a private one just for it).

## Known gotchas / landmines for future maintenance

- **`journald` defaulted to `Storage=volatile` on this host**, meaning a
  hard freeze wipes its own logs — there is no `journalctl -b -1` to
  diagnose a crash after the fact. Changed to `Storage=persistent` in
  `/etc/systemd/journald.conf`, but note this still only helps if the
  crash allows journald time to flush before power is lost; the freezes
  seen during this feature's development were hard enough that even
  after enabling persistence, the next crash still produced no prior-boot
  journal (confirmed: `/var/log/journal/<id>/system.journal` only
  contained the fresh boot). Don't assume forensic logs will be there.
- **This host has essentially no memory margin** — see "Memory
  constraints" at the top of this doc. Treat any new always-on process
  as a real cost, not a rounding error.
- **`pkill -f`/`pgrep -f <pattern>` can match the invoking shell's own
  command line** if the pattern text is a substring of the ssh/bash
  command that invokes it (e.g. `pkill -f "stream.mp3"` killing the SSH
  session running that exact command, or `pgrep -f "yt-dlp --no-playlist"`
  matching its own `bash -c` argv). Kill by PID (`pgrep -x ffmpeg`, or
  `ps aux | grep '[f]fmpeg'` with the bracket trick) instead of a pattern
  that might self-match.
- **snd-aloop's card index isn't stable across reboots** (it was `card 1`
  once, `card 0` after a reboot) — anything referencing it must use
  name-based ALSA addressing (`hw:Loopback,0`), never a bare index
  (`hw:1,0`).
- **`pipewire.service` needs `After=`/`Requires=systemd-modules-load.service`**
  — without it, a reboot can start PipeWire before `snd-aloop` finishes
  loading; PipeWire's `support.null-audio-sink`/ALSA nodes referencing a
  not-yet-existent device fail their whole context creation fatally
  (exit code 234, "failed to create context") rather than degrading
  gracefully, taking down the entire PipeWire daemon.
- **`support.null-audio-sink`'s monitor ports are not fed by its playback
  input**, even with `monitor.passthrough = true` — confirmed by direct
  A/B test against a real hardware sink, which worked immediately. Don't
  use a null sink as a "tap point" to capture/verify a stream; link
  straight to the real destination instead.
- **RAOP-discover-generated node names contain literal `\032` text**
  (four characters: backslash, 0, 3, 2) instead of decoding to a space —
  match RAOP sinks by `node.description` (clean) or by IP address
  embedded in `node.name` (e.g. `.10.0.1.10.` as a substring — this
  deployment matches by IP since it's configurable and doesn't depend on
  the speaker's display name), never construct/parse the RAOP `node.name`
  by hand.
- **Docker masks `/proc/asound` from containers by default** (confirmed
  general Docker behavior on this host, not container-specific) — a
  container's own ALSA card-index/name resolution silently fails without
  it (`Cannot get card index for ...`, regardless of the exact device
  string tried). `privileged: true` is what actually exposes it (a plain
  `-v /proc/asound:/proc/asound` bind-mount is blocked outright by the
  container runtime's proc-safety check, and `pid: host` breaks this
  particular image's own init instead of helping).
- **A pure-software ALSA card (snd-aloop) has no real mixer element at
  all** — `amixer -c 0 controls` shows only `PCM Notify`/`PCM Slave *`,
  nothing named `PCM` or `Master` that OwnTone's default mixer lookup
  can attach to. An ALSA `softvol` plugin wrapper creates a synthetic
  one, but see the toggle-reliability caveat above — its existence
  appears tied to whether the underlying PCM is currently open,
  producing an intermittent race with OwnTone's own select sequence.
- **A single stray uncommented line can break the whole config file.**
  `owntone.conf`'s example blocks (e.g. `#alsa "card name" { ... }`) have
  every line individually prefixed with `#`, including ones deep inside;
  a blind `sed` replace across the whole file can un-comment a same-text
  line meant to stay disabled elsewhere, producing `no such option 'x'`
  parse errors. Always target the specific line number (or scope the
  first match only) when editing this file.
- **A concurrent second subscriber to `/stream.mp3` can pause OwnTone's
  main playback** if OwnTone's own ALSA output is also active at the
  time (see "Why this shape" above) — this is why the final design
  doesn't touch `/stream.mp3` at all.
