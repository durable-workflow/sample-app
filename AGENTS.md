# Repository agent guidance

When a user asks to create and run a Durable Workflow workflow and activity,
start with the repository playground instead of the conformance matrix.

- Ask which first-party SDK they want when the request does not say: `php`,
  `python`, or `rust`.
- Run `scripts/playground <language>`. It scaffolds missing caller-owned source,
  preserves existing source, launches an isolated published Server, and prints
  the completed result and exact Waterline run URL.
- Treat the printed effective contract and `Worker ready` checkpoint as the
  source of truth for workflow type, activity type, task queue, worker command,
  start command, and expected result. Do not repeat scenario identities in an
  ad hoc shell command.
- Use `--source <directory>` when the user names a project directory or wants
  the generated worker outside `.playground/`.
- Treat `playground/templates/` as scaffold material, not as a canonical
  conformance worker. Edit the caller-owned copy for the user's workflow and
  activity.
- The default PHP scaffold uses the framework-neutral SDK's Laravel bridge.
  Preserve its container injection, application configuration, PSR logging,
  and SDK test fake when editing the caller-owned workflow and activity.
- For a framework-free PHP process, run
  `scripts/playground scaffold php --standalone --source <dir>` and use the
  installed SDK package's own worker and client examples. Preserve the
  role-specific credential variables and pass a full Cloud namespace runtime
  URI unchanged.
- Run `scripts/playground doctor` before diagnosing toolchain or Docker access.
  Do not install operating-system packages, run `rustup`, compile `dw`, or
  rebuild SDK toolchains during Codespaces setup or recovery.
