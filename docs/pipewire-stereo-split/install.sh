#!/bin/bash
# One-click installer for the PipeWire stereo-split feature (see
# ../pipewire-stereo-split-plan.md for the full writeup of what this does
# and why). Run as root on the OwnTone host. Idempotent — safe to re-run.
#
# What this sets up:
#   - PipeWire + WirePlumber as a system-wide service (not the usual
#     per-user session — this host is headless) — installed but NOT
#     enabled at boot. This host is severely memory-constrained (see the
#     plan doc's "Memory constraints" section — chronic near-total swap
#     usage from other services even at idle), so PipeWire only runs
#     while the feature is actually toggled on, started/stopped by a
#     lightweight watcher (see below) rather than running 24/7.
#   - snd-aloop: OwnTone's own ALSA output writes real audio to
#     hw:Loopback,0 whenever "PipeWire - Stereo Pair" is selected in its
#     UI; PipeWire captures the other side (hw:Loopback,1, exposed as
#     node "aloop-capture")
#   - An ALSA softvol wrapper so that output toggle is actually
#     selectable (the raw loopback device has no real mixer element —
#     note this remains somewhat flaky, see the plan doc's final section)
#   - PipeWire's RAOP-discover module, so the two AirPlay speakers appear
#     as PipeWire sinks
#   - ytb-stereo-split-linker.sh (+ its systemd service, NOT enabled at
#     boot): keeps aloop-capture linked to the two speakers, split by
#     channel, while running
#   - ytb-stereo-split-watcher.sh (+ its systemd service, enabled at
#     boot): a lightweight poller (plain curl, no PipeWire client
#     libraries loaded) that starts pipewire+wireplumber+the linker when
#     the OwnTone toggle is selected, and stops them again when it isn't
#   - The OwnTone container's docker-compose.yml and owntone.conf
#
# What you MUST edit before running:
#   - SPEAKER_MAIN_IP / SPEAKER_SUB_IP below (or edit
#     /etc/ytb-stereo-split.conf afterwards)
#   - OWNTONE_COMPOSE_DIR / OWNTONE_CONF_PATH if this host's OwnTone
#     install lives somewhere other than this deployment's paths
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --- Configure these for your setup -----------------------------------
SPEAKER_MAIN_IP="${SPEAKER_MAIN_IP:-10.0.1.10}"
SPEAKER_SUB_IP="${SPEAKER_SUB_IP:-10.0.1.11}"
OWNTONE_COMPOSE_DIR="${OWNTONE_COMPOSE_DIR:-/mnt/appsrv/docker/owntone}"
OWNTONE_CONF_PATH="${OWNTONE_CONF_PATH:-/opt/docker/owntone/config/owntone.conf}"
# ------------------------------------------------------------------------

echo "==> Installing packages"
apt-get update
apt-get install -y pipewire pipewire-audio-client-libraries pipewire-alsa wireplumber

echo "==> Loading snd-aloop (backs the OwnTone toggle's ALSA device)"
modprobe snd-aloop
echo "snd-aloop" > /etc/modules-load.d/snd-aloop.conf

echo "==> Creating the pipewire system user"
if ! getent group pipewire >/dev/null; then
  groupadd --system pipewire
fi
if ! id pipewire >/dev/null 2>&1; then
  useradd --system --no-create-home --shell /usr/sbin/nologin \
    -g pipewire -G audio pipewire
fi
mkdir -p /var/lib/pipewire/.config /var/lib/pipewire/.local/state \
  /var/lib/pipewire/.cache /var/lib/pipewire/.local/share
chown -R pipewire:pipewire /var/lib/pipewire

echo "==> Installing systemd units"
install -m 644 "$SCRIPT_DIR/pipewire.service" /etc/systemd/system/pipewire.service
install -m 644 "$SCRIPT_DIR/wireplumber.service" /etc/systemd/system/wireplumber.service
install -m 644 "$SCRIPT_DIR/ytb-stereo-split-linker.service" /etc/systemd/system/ytb-stereo-split-linker.service
install -m 644 "$SCRIPT_DIR/ytb-stereo-split-watcher.service" /etc/systemd/system/ytb-stereo-split-watcher.service

echo "==> Installing PipeWire config (RAOP discovery, aloop-autoprofile exclusion, capture node)"
mkdir -p /etc/pipewire/pipewire.conf.d /etc/wireplumber/main.lua.d
install -m 644 "$SCRIPT_DIR/30-raop-discover.conf" /etc/pipewire/pipewire.conf.d/30-raop-discover.conf
install -m 644 "$SCRIPT_DIR/10-aloop-capture.conf" /etc/pipewire/pipewire.conf.d/10-aloop-capture.conf
install -m 644 "$SCRIPT_DIR/51-disable-aloop-autoprofile.lua" /etc/wireplumber/main.lua.d/51-disable-aloop-autoprofile.lua

echo "==> Installing the stereo-split linker/watcher + speaker config"
install -m 755 "$SCRIPT_DIR/ytb-stereo-split-linker.sh" /usr/local/bin/ytb-stereo-split-linker.sh
install -m 755 "$SCRIPT_DIR/ytb-stereo-split-watcher.sh" /usr/local/bin/ytb-stereo-split-watcher.sh
if [ ! -f /etc/ytb-stereo-split.conf ]; then
  sed -e "s/^MAIN_IP=.*/MAIN_IP=$SPEAKER_MAIN_IP/" \
      -e "s/^SUB_IP=.*/SUB_IP=$SPEAKER_SUB_IP/" \
      "$SCRIPT_DIR/ytb-stereo-split.conf" > /etc/ytb-stereo-split.conf
  echo "    wrote /etc/ytb-stereo-split.conf (MAIN=$SPEAKER_MAIN_IP, SUB=$SPEAKER_SUB_IP)"
else
  echo "    /etc/ytb-stereo-split.conf already exists, leaving it alone"
fi

echo "==> Enabling only the watcher at boot (PipeWire/WirePlumber/linker start on demand)"
systemctl daemon-reload
systemctl disable --now pipewire.service wireplumber.service ytb-stereo-split-linker.service 2>/dev/null
systemctl enable --now ytb-stereo-split-watcher.service

echo "==> Configuring the OwnTone container (asound.conf softvol wrapper)"
install -m 644 "$SCRIPT_DIR/asound.conf" "$OWNTONE_COMPOSE_DIR/config/asound.conf"

COMPOSE_FILE="$OWNTONE_COMPOSE_DIR/docker-compose.yml"
if [ ! -f "$COMPOSE_FILE" ]; then
  echo "WARNING: $COMPOSE_FILE not found — skipping docker-compose changes." >&2
  echo "Add these to your OwnTone service manually (see owntone-docker-compose.yml" >&2
  echo "in this folder for the reference version):" >&2
  echo "  privileged: true" >&2
  echo "  devices: [\"/dev/snd:/dev/snd\"]" >&2
  echo "  volumes: [\"./config/asound.conf:/etc/asound.conf:ro\"]" >&2
else
  RESTART_NEEDED=0
  if ! grep -q "privileged: true" "$COMPOSE_FILE"; then
    python3 - "$COMPOSE_FILE" <<'PYEOF'
import sys
path = sys.argv[1]
with open(path) as f:
    lines = f.readlines()
out = []
for line in lines:
    out.append(line)
    if line.strip() == "network_mode: host":
        indent = line[: len(line) - len(line.lstrip())]
        out.append(indent + "privileged: true\n")
with open(path, "w") as f:
    f.writelines(out)
PYEOF
    RESTART_NEEDED=1
  fi
  if ! grep -q '"/dev/snd:/dev/snd"' "$COMPOSE_FILE"; then
    python3 - "$COMPOSE_FILE" <<'PYEOF'
import sys
path = sys.argv[1]
with open(path) as f:
    content = f.read()
if "devices:" not in content:
    content = content.replace(
        "    volumes:",
        '    devices:\n      - "/dev/snd:/dev/snd"\n    volumes:',
    )
with open(path, "w") as f:
    f.write(content)
PYEOF
    RESTART_NEEDED=1
  fi
  if ! grep -q "asound.conf" "$COMPOSE_FILE"; then
    echo "      - ./config/asound.conf:/etc/asound.conf:ro" >> "$COMPOSE_FILE"
    RESTART_NEEDED=1
  fi

  if [ ! -f "$OWNTONE_CONF_PATH" ]; then
    echo "WARNING: $OWNTONE_CONF_PATH not found — configure the audio{} block" >&2
    echo "manually using owntone.conf.audio-block.excerpt in this folder as a reference." >&2
  else
    cp "$OWNTONE_CONF_PATH" "$OWNTONE_CONF_PATH.bak.$(date +%s 2>/dev/null || echo pre-install)"
    if ! grep -q 'card = "stereo_split_out"' "$OWNTONE_CONF_PATH"; then
      sed -i \
        -e '0,/nickname = "Computer"/s//nickname = "PipeWire - Stereo Pair"/' \
        -e 's/#\ttype = "alsa"/\ttype = "alsa"/' \
        -e 's/#\tcard = "default"/\tcard = "stereo_split_out"/' \
        "$OWNTONE_CONF_PATH"
      # mixer/mixer_device: only the FIRST commented occurrence in the file
      # is the one inside the active "audio {}" block — later ones are
      # inside a commented-out example block further down and must stay
      # commented (see plan doc's gotchas).
      python3 - "$OWNTONE_CONF_PATH" <<'PYEOF'
import sys
path = sys.argv[1]
with open(path) as f:
    lines = f.readlines()
done_mixer = done_device = False
for i, line in enumerate(lines):
    if not done_mixer and line.strip() == '#\tmixer = ""':
        lines[i] = '\tmixer = "Stereo Split"\n'
        done_mixer = True
    elif not done_device and line.strip() == '#\tmixer_device = ""':
        lines[i] = '\tmixer_device = "stereo_split_out"\n'
        done_device = True
with open(path, "w") as f:
    f.writelines(lines)
PYEOF
      RESTART_NEEDED=1
    fi
  fi

  if [ "$RESTART_NEEDED" -eq 1 ]; then
    PLAYER_STATE=$(curl -s http://127.0.0.1:3689/api/player 2>/dev/null | python3 -c "import json,sys; print(json.load(sys.stdin).get('state','unknown'))" 2>/dev/null || echo "unknown")
    if [ "$PLAYER_STATE" = "play" ]; then
      echo "WARNING: OwnTone is currently playing. Restarting it now will" >&2
      echo "interrupt playback. Re-run this script when it's idle, or restart" >&2
      echo "manually with: (cd $OWNTONE_COMPOSE_DIR && docker compose up -d)" >&2
    else
      echo "==> Recreating the OwnTone container"
      (cd "$OWNTONE_COMPOSE_DIR" && docker compose up -d)
    fi
  fi
fi

echo "==> Done."
echo "Toggle the feature from OwnTone's web UI/Remote — the output named"
echo "\"PipeWire - Stereo Pair\" — or via:"
echo "  curl -X PUT http://127.0.0.1:3689/api/outputs/0 -H 'Content-Type: application/json' -d '{\"selected\":true}'"
