#!/bin/bash
# Lightweight watcher for the stereo-split feature. Does NOT depend on
# PipeWire being alive — just polls OwnTone's own API (a plain curl) for
# whether 'PipeWire - Stereo Pair' (id=0) is selected, and starts/stops
# PipeWire + WirePlumber + the linker service on demand.
#
# Why: this host is severely memory-constrained (under 1GB RAM, chronic
# near-total swap usage from Home Assistant/Jellyfin/etc — see
# docs/pipewire-stereo-split-plan.md). Running PipeWire/WirePlumber 24/7
# costs ~20MB baseline plus periodic polling churn that isn't worth
# paying when the feature isn't in use — this watcher itself is far
# lighter (a single curl + tiny python parse, no PipeWire client
# libraries loaded) so the steady-state cost of 'is the feature available
# at all' drops close to zero.
#
# It also auto-resyncs the two independent AirPlay/RAOP sessions
# (IDLE_MINUTES_BEFORE_RESYNC in /etc/ytb-stereo-split.conf) — confirmed
# live that the two speakers gradually drift out of L/R sync the longer
# a session stays connected, since each has its own independent RAOP
# clock with no shared reference. A full reconnect (same effect as
# toggling the OwnTone output off/on) resets it, but only once playback
# has been idle for a while, so it never interrupts an active listening
# session.
set -uo pipefail

IDLE_MINUTES_BEFORE_RESYNC=0
[ -f /etc/ytb-stereo-split.conf ] && source /etc/ytb-stereo-split.conf

is_selected() {
  curl -s --max-time 3 http://127.0.0.1:3689/api/outputs 2>/dev/null | python3 -c '
import json, sys
try:
    data = json.load(sys.stdin)
except Exception:
    print("0")
    sys.exit(0)
for o in data.get("outputs", []):
    if o.get("id") == "0":
        print("1" if o.get("selected") else "0")
        sys.exit(0)
print("0")
' 2>/dev/null || echo 0
}

is_playing() {
  curl -s --max-time 3 http://127.0.0.1:3689/api/player 2>/dev/null | python3 -c '
import json, sys
try:
    data = json.load(sys.stdin)
except Exception:
    print("0")
    sys.exit(0)
print("1" if data.get("state") == "play" else "0")
' 2>/dev/null || echo 0
}

pipewire_running() {
  systemctl is-active --quiet pipewire.service
}

start_stack() {
  systemctl start pipewire.service
  sleep 2
  systemctl start wireplumber.service
  sleep 2
  systemctl start ytb-stereo-split-linker.service
}

stop_stack() {
  systemctl stop ytb-stereo-split-linker.service
  systemctl stop wireplumber.service
  systemctl stop pipewire.service
}

LAST_PLAYING_AT=$(date +%s)

while true; do
  SELECTED=$(is_selected)
  PLAYING=$(is_playing)
  NOW=$(date +%s)

  if [ "$PLAYING" = "1" ]; then
    LAST_PLAYING_AT=$NOW
  fi

  if [ "$SELECTED" = "1" ] && ! pipewire_running; then
    start_stack
  elif [ "$SELECTED" = "0" ] && pipewire_running; then
    stop_stack
  elif [ "$SELECTED" = "1" ] && pipewire_running \
       && [ "${IDLE_MINUTES_BEFORE_RESYNC:-0}" -gt 0 ] 2>/dev/null; then
    IDLE_MIN=$(( (NOW - LAST_PLAYING_AT) / 60 ))
    if [ "$IDLE_MIN" -ge "$IDLE_MINUTES_BEFORE_RESYNC" ]; then
      logger -t ytb-stereo-split "auto-resync after ${IDLE_MIN}m idle (IDLE_MINUTES_BEFORE_RESYNC=$IDLE_MINUTES_BEFORE_RESYNC)"
      stop_stack
      sleep 2
      start_stack
      LAST_PLAYING_AT=$(date +%s)
    fi
  fi

  sleep 5
done
