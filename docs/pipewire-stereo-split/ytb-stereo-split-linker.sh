#!/bin/bash
# Links OwnTone's own ALSA output (via the snd-aloop bridge's capture
# side, node 'aloop-capture') directly to the two AirPlay speakers,
# split by channel. Whether real audio actually flows is controlled
# entirely by OwnTone's own output selection UI (the "PipeWire - Stereo
# Pair" output) — when deselected, OwnTone just doesn't write anything to
# the loopback, so the speakers get silence; no separate on/off toggle
# logic is needed here. Speaker IPs come from /etc/ytb-stereo-split.conf.
#
# Runs as a persistent loop (not one-shot) so it self-heals if the
# speakers join the network after this starts, or reconnect after going
# offline — each iteration just re-asserts the links if they're missing.
set -uo pipefail
export XDG_RUNTIME_DIR=/run/pipewire

source /etc/ytb-stereo-split.conf

while true; do
  MAIN_IP="$MAIN_IP" SUB_IP="$SUB_IP" python3 <<'PYEOF'
import subprocess, json, os

MAIN_IP = os.environ["MAIN_IP"]
SUB_IP = os.environ["SUB_IP"]

def dump():
    return json.loads(subprocess.check_output(["pw-dump"]))

def find_node_id(data, match):
    for obj in data:
        if obj.get("type") != "PipeWire:Interface:Node":
            continue
        props = obj.get("info", {}).get("props", {})
        if match(props):
            return obj["id"]
    return None

def find_ports(data, node_id):
    ports = {}
    for obj in data:
        if obj.get("type") != "PipeWire:Interface:Port":
            continue
        props = obj.get("info", {}).get("props", {})
        if props.get("node.id") == node_id:
            ports[props.get("port.name")] = obj["id"]
    return ports

def existing_links(data):
    links = set()
    for obj in data:
        if obj.get("type") != "PipeWire:Interface:Link":
            continue
        info = obj.get("info", {})
        links.add((info.get("output-port-id"), info.get("input-port-id")))
    return links

def link(src, dst):
    subprocess.run(["pw-link", str(src), str(dst)], capture_output=True)

def has_ip(ip):
    needle = "." + ip + "."
    return lambda p: needle in str(p.get("node.name", ""))

data = dump()
capture_node = find_node_id(data, lambda p: p.get("node.name") == "aloop-capture")
main_node = find_node_id(data, has_ip(MAIN_IP))
sub_node = find_node_id(data, has_ip(SUB_IP))

if not (capture_node and main_node and sub_node):
    raise SystemExit(0)

capture_ports = find_ports(data, capture_node)
main_ports = find_ports(data, main_node)
sub_ports = find_ports(data, sub_node)
links = existing_links(data)

fl = capture_ports.get("capture_FL")
fr = capture_ports.get("capture_FR")

for name, pid in main_ports.items():
    if name.startswith("send_") and (fl, pid) not in links:
        link(fl, pid)
for name, pid in sub_ports.items():
    if name.startswith("send_") and (fr, pid) not in links:
        link(fr, pid)
PYEOF
  sleep 5
done
