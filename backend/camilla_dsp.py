"""
camilla_dsp.py
Python port of utils/camillaDsp.js.

Spawns camilladsp.exe as a subprocess and controls it live over its
websocket API. Uses a File capture device (not a live audio input),
so it plays a bundled test-sample WAV through whatever filter settings
are currently pushed — audio plays out of THIS machine's speakers,
not streamed to the browser.

Swap TEST_SAMPLE_PATH once the real listening-test audio is ready.
"""

import os
import time
import json
import subprocess
import yaml
import websocket  # pip install websocket-client

# This file lives in backend/, while the audio lives in data/ at the project
# root — one level up. Anchoring on the project root rather than on this
# file's own folder means the paths keep working wherever the service is
# launched from, and would have survived this move on their own.
_BACKEND_DIR = os.path.dirname(os.path.abspath(__file__))
_PROJECT_ROOT = os.path.dirname(_BACKEND_DIR)

CAMILLA_EXE = os.path.join(_BACKEND_DIR, "camilladsp.exe")
CAMILLA_WS_PORT = 1234

# Fallback sample, used only if a session somehow has no sample assigned.
TEST_SAMPLE_PATH = os.path.join(_PROJECT_ROOT, "data", "audio", "test-sample.wav")

# Folder holding sample1.wav .. sample10.wav. The test walks through all ten,
# one per question (see adaptive_test.py), so the capture filename changes
# between questions — apply_filters() rebuilds and re-pushes the whole config
# each time, which is what makes the clip switch actually take effect.
SAMPLES_DIR = os.path.join(_PROJECT_ROOT, "data", "audio", "samples")

_process = None
_ws = None
_ready = False


def _build_config(bassGain=0, trebleGain=0, presenceGain=0, sample=None):
    capture_path = os.path.join(SAMPLES_DIR, sample) if sample else TEST_SAMPLE_PATH
    return {
        "devices": {
            "samplerate": 48000,
            "chunksize": 1024,
            "capture": {"type": "WavFile", "filename": capture_path},
            "playback": {"type": "Wasapi", "channels": 2, "exclusive": False},
        },
        "filters": {
            "bass_shelf": {
                "type": "Biquad",
                "parameters": {"type": "Lowshelf", "freq": 100, "q": 0.7, "gain": bassGain},
            },
            "treble_shelf": {
                "type": "Biquad",
                "parameters": {"type": "Highshelf", "freq": 8000, "q": 0.7, "gain": trebleGain},
            },
            "presence_peak": {
                "type": "Biquad",
                "parameters": {"type": "Peaking", "freq": 3000, "q": 1.4, "gain": presenceGain},
            },
        },
        "pipeline": [
            {"type": "Filter", "channels": [0, 1], "names": ["bass_shelf", "treble_shelf", "presence_peak"]}
        ],
    }


def start():
    """Starts camilladsp.exe if not already running, then connects."""
    global _process
    if _process is not None:
        return
    print("Starting CamillaDSP...")
    _process = subprocess.Popen([CAMILLA_EXE, "-p", str(CAMILLA_WS_PORT), "-w"])
    _connect()


def _connect(retries=10):
    global _ws, _ready
    for _ in range(retries):
        try:
            _ws = websocket.create_connection(f"ws://127.0.0.1:{CAMILLA_WS_PORT}", timeout=2)
            _ready = True
            print("Connected to CamillaDSP control socket")
            return
        except Exception:
            time.sleep(0.5)
    _ready = False
    print("Could not connect to CamillaDSP control socket.")


def _push_config(params):
    global _ws, _ready
    if not _ready or _ws is None:
        return False
    config_yaml = yaml.dump(_build_config(**params))
    try:
        _ws.send(json.dumps({"SetConfig": config_yaml}))
        return True
    except Exception as e:
        print("Failed to push config:", e)
        return False


def apply_filters(bassGain=0, trebleGain=0, presenceGain=0, sample=None):
    """Plays the given sample (or the fallback test sample) through arbitrary filter values."""
    params = {"bassGain": bassGain, "trebleGain": trebleGain, "presenceGain": presenceGain, "sample": sample}
    ok = _push_config(params)
    return {"ok": ok, "params": params}


def stop():
    global _process
    if _process:
        _process.terminate()
        _process = None
