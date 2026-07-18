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
set -uo pipefail

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

pipewire_running() {
  systemctl is-active --quiet pipewire.service
}

while true; do
  SELECTED=$(is_selected)

  if [ "$SELECTED" = "1" ] && ! pipewire_running; then
    systemctl start pipewire.service
    sleep 2
    systemctl start wireplumber.service
    sleep 2
    systemctl start ytb-stereo-split-linker.service
  elif [ "$SELECTED" = "0" ] && pipewire_running; then
    systemctl stop ytb-stereo-split-linker.service
    systemctl stop wireplumber.service
    systemctl stop pipewire.service
  fi

  sleep 5
done
