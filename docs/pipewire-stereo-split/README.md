# PipeWire stereo-split config

Deployed config/scripts for the stereo-split feature described in
[../pipewire-stereo-split-plan.md](../pipewire-stereo-split-plan.md).
These are pulled directly from the live chainedbox deployment — this
folder is the source of truth for what's actually running.

**Known limitation:** toggling "PipeWire - Stereo Pair" in OwnTone's UI
is not 100% reliable — see the plan doc's final section. Selecting it
sometimes fails with a 400 (retry, or briefly hold the loopback PCM open
from another process — see the plan doc's mixer-race writeup). Once
selected, the actual audio path is solid.

**This host is severely memory-constrained** (under 1GB RAM, chronic
near-total swap usage from other unrelated services even at idle — see
the plan doc's "Memory constraints" section). PipeWire/WirePlumber are
NOT run 24/7 as a result — they start only while the feature is actually
toggled on, via a lightweight watcher, and stop again when it's toggled
off, to avoid holding memory this host doesn't reliably have to spare.

## Install

```
sudo SPEAKER_MAIN_IP=10.0.1.10 SPEAKER_SUB_IP=10.0.1.11 ./install.sh
```

Edit `SPEAKER_MAIN_IP`/`SPEAKER_SUB_IP` (or `OWNTONE_COMPOSE_DIR`/
`OWNTONE_CONF_PATH` if paths differ) at the top of `install.sh`, or pass
them as env vars as shown above. Safe to re-run — it only changes what
isn't already in place, and skips restarting OwnTone if it's mid-playback.

## Architecture (final)

OwnTone's own ALSA output writes to `hw:Loopback,0` (gated entirely by
whether "PipeWire - Stereo Pair" is selected in OwnTone's UI).
`ytb-stereo-split-watcher.sh` runs 24/7 (it's cheap — a plain `curl` poll
every 5s, no PipeWire client libraries loaded) and starts/stops
`pipewire.service` + `wireplumber.service` + `ytb-stereo-split-linker.service`
based on that toggle. The linker itself keeps `aloop-capture` (the
loopback's other side, captured by PipeWire) linked directly to the two
AirPlay speakers, split by channel, for as long as it's running. No
stream-pulling `ffmpeg` process is needed — an earlier version of this
used one, but it caused OwnTone's own playback to intermittently
self-pause (a second concurrent subscriber conflicting with an
already-active ALSA session) and added an unnecessary MP3 encode/decode
round-trip. This version is direct PCM passthrough.

## Files

| File | Installed to |
|---|---|
| `ytb-stereo-split-watcher.sh` | `/usr/local/bin/ytb-stereo-split-watcher.sh` — always running, starts/stops everything else |
| `ytb-stereo-split-watcher.service` | `/etc/systemd/system/ytb-stereo-split-watcher.service` — enabled at boot |
| `ytb-stereo-split-linker.sh` | `/usr/local/bin/ytb-stereo-split-linker.sh` |
| `ytb-stereo-split-linker.service` | `/etc/systemd/system/ytb-stereo-split-linker.service` — **not** enabled at boot, started/stopped by the watcher |
| `ytb-stereo-split.conf` | `/etc/ytb-stereo-split.conf` — speaker IPs, edit here to change assignment |
| `pipewire.service` | `/etc/systemd/system/pipewire.service` — **not** enabled at boot, started/stopped by the watcher |
| `wireplumber.service` | `/etc/systemd/system/wireplumber.service` — **not** enabled at boot, started/stopped by the watcher |
| `30-raop-discover.conf` | `/etc/pipewire/pipewire.conf.d/30-raop-discover.conf` |
| `10-aloop-capture.conf` | `/etc/pipewire/pipewire.conf.d/10-aloop-capture.conf` — the `aloop-capture` node the linker connects from |
| `51-disable-aloop-autoprofile.lua` | `/etc/wireplumber/main.lua.d/51-disable-aloop-autoprofile.lua` |
| `asound.conf` | OwnTone container's `/etc/asound.conf` |

`20-stream-bridge.conf` is kept here for reference only (dead end from
an earlier intermediate approach — a `null-audio-sink`'s monitor ports
turned out not to be fed by its playback input at all — still present
but inert on chainedbox) — `install.sh` does not install it.

`owntone-docker-compose.yml` and `owntone.conf.audio-block.excerpt` are
**reference copies**, not installed verbatim — `install.sh` patches your
existing files instead, since they contain host-specific settings (media
paths, timezone, etc.) beyond this feature.
