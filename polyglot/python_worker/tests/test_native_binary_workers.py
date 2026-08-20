from __future__ import annotations

import importlib.util
import sys
import types
import unittest
from pathlib import Path
from typing import Any


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
            echo = activities.echo_native_binary_value(wire_payload)
            legacy_echo = activities.echo_value(wire_payload)
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
        self.assertNotIn("binary_evidence", legacy_echo)
        normalized_legacy_echo, legacy_evidence = workflows._complete_native_binary_roundtrip(
            self.payload,
            legacy_echo,
        )
        self.assertEqual(self.payload, normalized_legacy_echo["value"])
        self.assertEqual(
            self.payload["binary_base64"],
            legacy_evidence["activity"]["base64"],
        )

    def test_python_activity_rejects_a_base64_metadata_object(self) -> None:
        with self.assertRaisesRegex(TypeError, "expected native Python bytes"):
            activities.echo_native_binary_value(
                {
                    **self.payload,
                    "binary_native": {
                        "encoding": "base64",
                        "value": self.payload["binary_base64"],
                    },
                },
            )

    def test_shared_echo_round_trips_fixture_like_keys_as_ordinary_map_fields(self) -> None:
        payloads = [
            {"binary_native": "ordinary native field"},
            {"binary_base64": "ordinary base64 field"},
            {"binary_text": "ordinary text field"},
            {
                "binary_native": "ordinary native field",
                "binary_base64": "ordinary base64 field",
                "binary_text": "ordinary text field",
            },
            {"binary_native": b"\x00\xff"},
            {
                "binary_native": b"\x00\xff",
                "binary_base64": {"malformed": True},
                "binary_text": 42,
            },
        ]
        original_observation = activities._avro_observation
        activities._avro_observation = lambda: {"implementation": "fastavro"}
        try:
            for handler in (activities.echo_value, activities.echo_rust_value):
                for payload in payloads:
                    with self.subTest(handler=handler.__name__, payload=payload):
                        echo = handler(payload)
                        self.assertEqual(payload, echo["value"])
                        self.assertNotIn("binary_evidence", echo)
        finally:
            activities._avro_observation = original_observation

    def test_native_binary_echo_rejects_a_partial_binary_fixture(self) -> None:
        with self.assertRaisesRegex(TypeError, "expected native Python bytes"):
            activities.echo_native_binary_value(
                {"binary_base64": self.payload["binary_base64"]}
            )


class TypedErrorTaskCodecBoundaryTest(unittest.IsolatedAsyncioTestCase):
    async def test_rejects_every_non_avro_root_tag_before_decode_or_handler_work(self) -> None:
        codec_cases: list[tuple[str, bool, Any]] = [
            ("missing", False, None),
            ("empty", True, ""),
            ("json", True, "json"),
            ("unknown", True, "custom"),
            ("wrong_case", True, "Avro"),
            ("null", True, None),
            ("non_string", True, ["avro"]),
        ]
        original_serializer = activities.serializer

        class DecodeMustNotRun:
            @staticmethod
            def decode_envelope(*_args: object, **_kwargs: object) -> object:
                raise AssertionError("payload decode ran before the root task codec guard")

        activities.serializer = DecodeMustNotRun
        try:
            for name, present, codec in codec_cases:
                with self.subTest(codec=name):
                    client = _RecordingActivityClient()
                    task = self.task()
                    if present:
                        task["payload_codec"] = codec

                    outcome = await activities.handle_typed_error_task(
                        client,
                        "typed-error-worker",
                        task,
                    )

                    self.assertEqual("unsupported_payload_codec", outcome)
                    self.assertEqual(1, len(client.failures))
                    self.assertIn("unsupported_payload_codec", client.failures[0]["message"])
                    self.assertEqual("ValueError", client.failures[0]["failure_type"])
                    self.assertNotIn("details", client.failures[0])
        finally:
            activities.serializer = original_serializer

    async def test_valid_avro_tag_decodes_and_reaches_the_manual_handler(self) -> None:
        original_serializer = activities.serializer
        decoded: list[tuple[object, object]] = []

        class RecordingSerializer:
            @staticmethod
            def decode_envelope(raw: object, *, codec: object) -> list[dict[str, object]]:
                decoded.append((raw, codec))
                return [{"payload_codec": "customer-owned", "codec": "json"}]

        activities.serializer = RecordingSerializer
        try:
            client = _RecordingActivityClient()
            task = self.task()
            task["payload_codec"] = "avro"

            outcome = await activities.handle_typed_error_task(
                client,
                "typed-error-worker",
                task,
            )
        finally:
            activities.serializer = original_serializer

        self.assertEqual("handled", outcome)
        self.assertEqual([({"codec": "avro", "blob": "payload"}, "avro")], decoded)
        self.assertEqual(1, len(client.failures))
        failure = client.failures[0]
        self.assertEqual("PolyglotPythonTypedError", failure["failure_type"])
        self.assertEqual(
            {"payload_codec": "customer-owned", "codec": "json"},
            failure["details"]["structured"]["request"],
        )

    @staticmethod
    def task() -> dict[str, object]:
        return {
            "task_id": "typed-error-task",
            "activity_attempt_id": "typed-error-attempt",
            "activity_type": activities.PYTHON_TYPED_ERROR_ACTIVITY,
            "arguments": {"codec": "avro", "blob": "payload"},
        }


class _RecordingActivityClient:
    def __init__(self) -> None:
        self.failures: list[dict[str, Any]] = []

    async def fail_activity_task(self, **arguments: Any) -> None:
        self.failures.append(arguments)


if __name__ == "__main__":
    unittest.main()
