### 2026.08.15e
- Per-model Tok/s and Load columns: median generation speed from the recent request activity, and load time inferred from a load-triggering request's duration minus its inference time. Rows use auto-flowing even column tracks so stats stay evenly spaced.
- Removed the aggregate tok/s from the summary strip; the per-model column replaces it. The fourth summary cell is now plain GPU memory.

### 2026.08.15d
- Pinned the row action links against Unraid's global button styling (theme min-width was stretching the tint pill across the row) and widened the last summary cell so the GPU memory and tok/s reading no longer truncates.

### 2026.08.15c
- Row action links carry a faint color tint at rest (red for Unload, accent for Load) so they read as tappable on touch devices; hover/press deepens the tint.

### 2026.08.15b
- Model row actions are now quiet text links (muted uppercase, color on hover: red for Unload, accent for Load) instead of bordered buttons, with more separation from the TTL column.

### 2026.08.15a
- Redesigned the dashboard widget: models render as compact single-line rows under a sticky column-header (Model, State, Quant, Memory, TTL), state is a colored dot plus label instead of pulsing card borders, and the whole list fits without scrolling for typical model counts. The tab bar hides when only one server is configured.
- Real per-model VRAM for llama-swap servers: nvidia-smi compute-apps PIDs are matched to model processes via /proc cmdline, so the Memory column shows each model's true GPU footprint (works when the plugin host is the GPU host; column stays empty otherwise).
- Settings: the model card field toggles now recognize llama-swap (previously every field showed as unsupported for that type).

### 2026.08.15
- Fork: this is nphil/llmstats-unraid-plugin, continuing jo-sobo/llmstats-unraid-plugin (GPLv3) with llama-swap support.
- New server type: llama-swap (github.com/mostlygeek/llama-swap). Model list from /models with per-model loaded/unloaded state, quantization parsed from the running process command line (with description and id fallbacks), residency/TTL shown per loaded model, and a summary row with the llama-swap version, live GPU memory from /api/performance, and median generation tok/s from /api/metrics/stats.
- Working load/unload for llama-swap: load pins the model through /upstream/{model}/health (triggers the swap, waits for readiness, 120s budget), unload posts to /api/models/unload/{model}.
- Fixed autodetection misidentifying llama-swap as Ollama: llama-swap serves its own /api/version whose JSON also has a "version" key, which previously short-circuited detection and produced an online server with zero models. Detection now checks llama-swap first (commit/build_date in the version JSON, or /running answering) and only concludes Ollama when /api/tags also answers.
- Model cards support an optional TTL row (shown with the busy/idle field for llama-swap servers; omitted entirely elsewhere instead of rendering "Unavailable").

### 2026.06.23
- Model card border is now yellow for loading models and light blue for sleeping models.

### 2026.06.16
- Simplified config loading to rely on Unraid's `parse_plugin_cfg()`, which already overlays the user config onto `default.cfg`; `default.cfg` is now the single source of truth for defaults (no behavior change).

### 2026.06.13
- Fixed llama-server single-model mode detection when `/models` returns a top-level model list without router state; fixed `-m` models now show as loaded/idle instead of offering a load action.
- Clarified that llama-server single-model mode is supported for monitoring, while load/unload controls require router mode.
- Added a default-off "Load/unload controls" model card field. Unsupported server modes grey it out, hide per-model action space, and hide the unload-all control.
- Fixed the settings preview so it only renders fields that are both selected and supported.

### 2026.06.11
- Initial LLMStats release.
- Dashboard tile with one tab per configured server, online/offline status glow, and a server status card per tab.
- Model cards with configurable fields: quantization, memory, and busy/idle state.
- Ollama support: server status, available models, running models, metadata, memory usage, and model load/unload.
- llama-server support: health, model listing, router mode model state, slot busy/idle activity, and router mode model load/unload.
- Server type autodetection with manual fallback.
- Load unloaded models where supported, unload single model, and unload all loaded models with optional confirmation.
- Collapsed dashboard state with compact server status chips.
- Settings page with server management (add, remove, reorder, test), per-server model display fields, and setup guidance for both server types.
- Light and dark theme support.
