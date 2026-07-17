# PipeWire stereo-split config

Deployed config/scripts for the stereo-split feature described in
[../pipewire-stereo-split-plan.md](../pipewire-stereo-split-plan.md).
These are pulled directly from the live chainedbox deployment — this
folder is the source of truth for what's actually running.

**Known limitation:** toggling "PipeWire - Stereo Pair" in OwnTone's UI
is not 100% reliable — see the plan doc's final section. Selecting it
sometimes fails with a 400 (retry once or twice — it usually succeeds).
Once selected, the actual audio path is solid.

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
whether "PipeWire - Stereo Pair" is selected in OwnTone's UI — no
separate process to start/stop). `ytb-stereo-split-linker.sh` runs
continuously and keeps `aloop-capture` (the loopback's other side,
captured by PipeWire) linked directly to the two AirPlay speakers, split
by channel. No stream-pulling `ffmpeg` process is needed — an earlier
version of this used one, but it caused OwnTone's own playback to
intermittently self-pause (a second concurrent subscriber conflicting
with an already-active ALSA session) and added an unnecessary MP3
encode/decode round-trip. This version is direct PCM passthrough.

## Files

| File | Installed to |
|---|---|
| `ytb-stereo-split-linker.sh` | `/usr/local/bin/ytb-stereo-split-linker.sh` |
| `ytb-stereo-split.conf` | `/etc/ytb-stereo-split.conf` — speaker IPs, edit here to change assignment |
| `ytb-stereo-split.service` | `/etc/systemd/system/ytb-stereo-split.service` |
| `pipewire.service` | `/etc/systemd/system/pipewire.service` |
| `wireplumber.service` | `/etc/systemd/system/wireplumber.service` |
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
