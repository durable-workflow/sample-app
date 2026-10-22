#!/usr/bin/env python3
"""Check the trust stores actually used by the prepared Python runtime."""

import ssl
import sys

import pip
from pip._vendor import certifi as pip_certifi


def verify_bundle(path):
    context = ssl.create_default_context(cafile=path)
    names = {
        value
        for certificate in context.get_ca_certs()
        for component in certificate["subject"]
        for key, value in component
        if key == "commonName"
    }
    if not names or {"AAA Certificate Services", "emSign ECC Root CA - G3"} & names:
        raise RuntimeError(f"Unqualified CA trust bundle: {path}")
    print(f"Verified Python CA bundle: {path}")


if tuple(int(part) for part in pip.__version__.split(".")) < (26, 2, 2):
    raise RuntimeError(f"Patched pip 26.2.2 or newer is required, found {pip.__version__}")

verify_bundle(ssl.get_default_verify_paths().cafile)
verify_bundle(pip_certifi.where())
if "--pip-only" not in sys.argv:
    import certifi

    verify_bundle(certifi.where())
