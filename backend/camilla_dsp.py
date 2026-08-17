import os
import time
import json
import subprocess
import yaml
import websocket

_BACKEND_DIR = os.path.dirname(os.path.abspath(__file__))
_PROJECT_ROOT = os.path.dirname(_BACKEND_DIR)

CAMILLA_EXE = os.path.join(_BACKEND_DIR, "camilladsp.exe")
CAMILLA_WS_PORT = 1234
CONNECT_RETRIES = 10
RETRY_DELAY_SECONDS = 0.5
CONNECT_TIMEOUT_SECONDS = 2

SAMPLE_RATE = 48000
CHUNK_SIZE = 1024
STEREO_CHANNELS = [0, 1]

BASS_FREQ, BASS_Q = 100, 0.7
PRESENCE_FREQ, PRESENCE_Q = 3000, 1.4
TREBLE_FREQ, TREBLE_Q = 8000, 0.7

TEST_SAMPLE_PATH = os.path.join(_PROJECT_ROOT, "data", "audio", "test-sample.wav")

SAMPLES_DIR = os.path.join(_PROJECT_ROOT, "data", "audio", "samples")

_process = None
_ws = None
_ready = False


def _biquad(filter_type, freq, q, gain):
    return {
        "type": "Biquad",
        "parameters": {"type": filter_type, "freq": freq, "q": q, "gain": gain},
    }


def _build_config(bassGain=0, trebleGain=0, presenceGain=0, sample=None):
    capture_path = os.path.join(SAMPLES_DIR, sample) if sample else TEST_SAMPLE_PATH
    filters = {
        "bass_shelf": _biquad("Lowshelf", BASS_FREQ, BASS_Q, bassGain),
        "presence_peak": _biquad("Peaking", PRESENCE_FREQ, PRESENCE_Q, presenceGain),
        "treble_shelf": _biquad("Highshelf", TREBLE_FREQ, TREBLE_Q, trebleGain),
    }
    return {
        "devices": {
            "samplerate": SAMPLE_RATE,
            "chunksize": CHUNK_SIZE,
            "capture": {"type": "WavFile", "filename": capture_path},
            "playback": {"type": "Wasapi", "channels": 2, "exclusive": False},
        },
        "filters": filters,
        "pipeline": [
            {
                "type": "Filter",
                "channels": STEREO_CHANNELS,
                "names": list(filters),
            }
        ],
    }


def start():
    global _process
    if _process is not None:
        return
    print("Starting CamillaDSP...")
    _process = subprocess.Popen([CAMILLA_EXE, "-p", str(CAMILLA_WS_PORT), "-w"])
    _connect()


def _connect(retries=CONNECT_RETRIES):
    global _ws, _ready
    for _ in range(retries):
        try:
            _ws = websocket.create_connection(
                f"ws://127.0.0.1:{CAMILLA_WS_PORT}",
                timeout=CONNECT_TIMEOUT_SECONDS,
            )
            _ready = True
            print("Connected to CamillaDSP control socket")
            return
        except Exception:
            time.sleep(RETRY_DELAY_SECONDS)

    _ready = False
    print("Could not connect to CamillaDSP control socket.")


def _push_config(params):
    if not _ready or _ws is None:
        return False

    config_yaml = yaml.dump(_build_config(**params))
    try:
        _ws.send(json.dumps({"SetConfig": config_yaml}))
        return True
    except Exception as error:
        print("Failed to push config:", error)
        return False


def apply_filters(bassGain=0, trebleGain=0, presenceGain=0, sample=None):
    params = {
        "bassGain": bassGain,
        "trebleGain": trebleGain,
        "presenceGain": presenceGain,
        "sample": sample,
    }
    return {"ok": _push_config(params), "params": params}


def stop():
    global _process
    if _process:
        _process.terminate()
        _process = None
