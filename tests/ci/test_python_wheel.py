import base64
import csv
import hashlib
import importlib.util
import io
import pathlib
import tempfile
import unittest
import zipfile

MODULE = pathlib.Path(__file__).resolve().parents[2] / "docker/python/build-pip.py"
SPEC = importlib.util.spec_from_file_location("build_pip", MODULE)
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


class WheelIntegrityTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.path = pathlib.Path(temporary.name) / "fixture.whl"

    def wheel(self, change=None):
        files = {"pip/_vendor/urllib3/__init__.py": b"qualified vendor payload\n",
                 "pip-1.0.dist-info/METADATA": b"Name: pip\nVersion: 1.0\n"}
        rows = []
        for name, raw in files.items():
            digest = base64.urlsafe_b64encode(hashlib.sha256(raw).digest()).rstrip(b"=").decode()
            rows.append([name, "sha256=" + digest, str(len(raw))])
        record = "pip-1.0.dist-info/RECORD"
        rows.append([record, "", ""])
        if change:
            change(files, rows)
        stream = io.StringIO(newline="")
        csv.writer(stream).writerows(rows)
        with zipfile.ZipFile(self.path, "w") as archive:
            for name, raw in files.items():
                archive.writestr(name, raw)
            archive.writestr(record, stream.getvalue())
        return self.path

    def test_every_wheel_file_has_a_valid_hash_and_size(self):
        self.assertEqual(BUILDER.verify_record(self.wheel()), 3)

    def test_replaced_vendor_code_with_stale_record_is_refused(self):
        def change(files, rows):
            files["pip/_vendor/urllib3/__init__.py"] = b"changed after packing\n"
        with self.assertRaisesRegex(ValueError, "Invalid wheel RECORD entry"):
            BUILDER.verify_record(self.wheel(change))

    def test_unrecorded_file_is_refused(self):
        with self.assertRaisesRegex(ValueError, "exact files"):
            BUILDER.verify_record(self.wheel(lambda files, rows: rows.pop(0)))

    def test_wrong_recorded_size_is_refused(self):
        def change(files, rows):
            rows[0][2] = "1"
        with self.assertRaisesRegex(ValueError, "Invalid wheel RECORD entry"):
            BUILDER.verify_record(self.wheel(change))

    def test_record_cannot_claim_to_hash_itself(self):
        def change(files, rows):
            rows[-1][1] = "sha256=not-a-self-hash"
        with self.assertRaisesRegex(ValueError, "must not hash itself"):
            BUILDER.verify_record(self.wheel(change))


if __name__ == "__main__":
    unittest.main()
