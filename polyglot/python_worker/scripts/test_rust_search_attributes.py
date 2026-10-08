"""Reject plausible but incorrect typed values and cold recovery evidence."""

from copy import deepcopy
import unittest

from rust_search_attributes import DEFINITIONS, INITIAL, MUTATION, assert_recovery, assert_values


def event(kind, sequence, payload):
    return {"event_type": kind, "sequence": sequence, "payload": payload}


def update(attributes, sequence):
    return event("SearchAttributesUpserted", sequence, {
        "attributes": attributes,
        "attribute_types": {key: DEFINITIONS[key] for key, value in attributes.items() if value is not None},
    })


def history_fixture():
    first = update(deepcopy(INITIAL), 1)
    wait = event("SignalWaitOpened", 2, {"sequence": 2, "signal_wait_id": "original-wait", "signal_name": "search-release"})
    history = {"events": [first, wait,
        event("SignalReceived", 3, {"signal_id": "original-signal", "signal_name": "search-release"}),
        event("SignalApplied", 4, {"sequence": 2, "signal_id": "original-signal", "signal_wait_id": "original-wait"}),
        update(deepcopy(MUTATION), 5), event("WorkflowCompleted", 6, {})]}
    return history, {"upsert": deepcopy(first), "wait": deepcopy(wait)}


class RustSearchAttributeEvidenceTests(unittest.TestCase):
    def test_exact_recovery_and_equivalent_timezone_preserve_microseconds(self):
        history, parked = history_fixture()
        assert_recovery(history, parked)
        actual = {**INITIAL, "SearchTime": "2026-10-08T14:34:56.123456+02:00"}
        assert_values(actual, INITIAL)

    def test_rounded_integer_bool_integer_and_lost_microseconds_are_rejected(self):
        for key, value in [("SearchCount", 9_007_199_254_740_992), ("SearchFlag", 1),
                           ("SearchTime", "2026-10-08T12:34:56Z")]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                assert_values({**INITIAL, key: value}, INITIAL)

    def test_changed_original_upsert_and_wait_are_rejected(self):
        for index in [0, 1]:
            history, parked = history_fixture()
            history["events"][index]["payload"]["sequence"] = 999
            with self.subTest(index=index), self.assertRaises(RuntimeError):
                assert_recovery(history, parked)

    def test_changed_canonical_type_is_rejected(self):
        history, parked = history_fixture()
        history["events"][4]["payload"]["attribute_types"]["SearchKeyword"] = "string"
        with self.assertRaises(RuntimeError):
            assert_recovery(history, parked)

    def test_duplicate_upsert_completion_or_wrong_signal_is_rejected(self):
        for kind in ["upsert", "completion", "signal"]:
            history, parked = history_fixture()
            if kind == "signal":
                history["events"][3]["payload"]["signal_id"] = "different-signal"
            else:
                history["events"].append(deepcopy(history["events"][0 if kind == "upsert" else -1]))
            with self.subTest(kind=kind), self.assertRaises(RuntimeError):
                assert_recovery(history, parked)


if __name__ == "__main__":
    unittest.main()
