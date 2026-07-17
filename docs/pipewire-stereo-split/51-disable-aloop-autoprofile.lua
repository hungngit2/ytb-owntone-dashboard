alsa_monitor.rules = alsa_monitor.rules or {}
table.insert(alsa_monitor.rules, {
  matches = {
    {
      { "device.name", "matches", "alsa_card.platform-snd_aloop.0" },
    },
  },
  apply_properties = {
    ["device.disabled"] = true,
  },
})
