#!/usr/bin/env python3
"""Verify the official parent's system bundle before bootstrap requests."""

from pathlib import Path
import ssl

from export_trust import export_bundle


def prepare():
    state = Path("/tmp/sample-app-python-bootstrap")
    state.mkdir(exist_ok=True)
    # This stage qualifies curl's system trust, not the parent's old pip.
    # pip remains offline until the existing wheel producer and pip guard pass.
    return export_bundle(
        Path(ssl.get_default_verify_paths().cafile), state,
        state / "trust.env", state / "outputs",
        clients=("curl", "git", "python"),
    )


if __name__ == "__main__":
    prepare()
