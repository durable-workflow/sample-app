#!/usr/bin/env python3
"""Check the trust stores actually used by the prepared Python runtime."""

import ssl
import sys
from importlib.metadata import version

import pip
from pip._vendor import certifi as pip_certifi
from pip._vendor import urllib3
from pip._vendor.packaging.version import Version


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


if Version(pip.__version__) < Version("26.2.2"):
    raise RuntimeError(f"Patched pip 26.2.2 or newer is required, found {pip.__version__}")
if Version(urllib3.__version__) < Version("2.8.0"):
    raise RuntimeError(f"Patched pip-bundled urllib3 2.8.0 or newer is required, found {urllib3.__version__}")
if Version(version("setuptools")) < Version("78.1.1"):
    raise RuntimeError("Patched setuptools 78.1.1 or newer is required")

verify_bundle(ssl.get_default_verify_paths().cafile)
verify_bundle(pip_certifi.where())
if "--pip-only" not in sys.argv:
    import certifi

    verify_bundle(certifi.where())
