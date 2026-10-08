# Published SDK child recovery

This qualification extends the existing child matrix under
durable-workflow/.github#122. Preserve all nine successful parent/child
directions and add the five Rust-involving directions for typed child failure
and cold worker recovery.

Recovery must retain the original parent and child runs. Park each child on a
declared signal, SIGKILL all three workers, acknowledge the signal while those
processes are absent, and start fresh workers. Require one child schedule,
start and completion in the original parent's history and one completion of
each original run. Failure cases require the actual SDK's typed child failure,
matched to the linked failed child and durable parent lifecycle.

Implementation and published-stack checks are tracked in the draft pull request.
