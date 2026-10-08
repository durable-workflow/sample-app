# Published remote activity retry and recovery

This experiment extends the portable SDK audit with all five Rust-involving
workflow/activity directions: PHP → Rust, Python → Rust, Rust → PHP,
Rust → Python and Rust → Rust.

For each direction, exercise a retryable first-attempt failure and a worker
SIGKILL during the first leased attempt. Require the original workflow run and
activity execution, a distinct second attempt, the original total deadline,
one activity result and one workflow completion. Submit the old claim through
the published SDK and require explicit stale completion refusal without
changing durable history.

The command and published qualification are being implemented in this draft.
Use only an isolated published Server stack. Task resources must be removed
after success or failure. This checks durable results and claim fencing. It
does not promise exactly-once external side effects.
