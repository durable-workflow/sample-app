from __future__ import annotations

import importlib.util
import sys
import types
import unittest
from pathlib import Path


class _Definitions:
    @staticmethod
    def defn(**_options):  # type: ignore[no-untyped-def]
        return lambda value: value

    @staticmethod
    def signal(_name):  # type: ignore[no-untyped-def]
        return lambda value: value

    @staticmethod
    def query(_name):  # type: ignore[no-untyped-def]
        return lambda value: value


durable_workflow = types.ModuleType("durable_workflow")
durable_workflow.Client = object
durable_workflow.TransportRetryPolicy = object
durable_workflow.Worker = object
durable_workflow.activity = _Definitions
durable_workflow.serializer = object
durable_workflow.workflow = _Definitions
durable_workflow_errors = types.ModuleType("durable_workflow.errors")
durable_workflow_errors.ActivityFailed = Exception
sys.modules["durable_workflow"] = durable_workflow
sys.modules["durable_workflow.errors"] = durable_workflow_errors


def load_module(name: str, path: Path):  # type: ignore[no-untyped-def]
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


polyglot_root = Path(__file__).parents[2]
activities = load_module("polyglot_native_activities", polyglot_root / "python_worker" / "activities.py")
workflows = load_module("polyglot_native_workflows", polyglot_root / "python_workflow" / "workflow.py")


class NativeBinaryWorkerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.payload = {
            "binary_base64": "cG9seWdsb3QtYmluYXJ5AP8B",
            "binary_text": "polyglot-binary",
        }

    def test_python_workflow_and_activity_require_native_bytes(self) -> None:
        wire_payload = workflows._native_binary_payload(self.payload)
        self.assertIs(type(wire_payload["binary_native"]), bytes)
        self.assertEqual(b"polyglot-binary\x00\xff\x01", wire_payload["binary_native"])

        original_observation = activities._avro_observation
        activities._avro_observation = lambda: {"implementation": "fastavro"}
        try:
            echo = activities.echo_value(wire_payload)
        finally:
            activities._avro_observation = original_observation
        normalized_echo, evidence = workflows._complete_native_binary_roundtrip(
            self.payload,
            echo,
        )

        self.assertEqual(self.payload, normalized_echo["value"])
        self.assertEqual("bytes", evidence["activity"]["native_type"])
        self.assertEqual(self.payload["binary_base64"], evidence["activity"]["base64"])
        self.assertEqual(self.payload["binary_base64"], evidence["workflow"]["base64"])

    def test_python_activity_rejects_a_base64_metadata_object(self) -> None:
        with self.assertRaisesRegex(TypeError, "expected native Python bytes"):
            activities.echo_value(
                {
                    **self.payload,
                    "binary_native": {
                        "encoding": "base64",
                        "value": self.payload["binary_base64"],
                    },
                },
            )


if __name__ == "__main__":
    unittest.main()
