use std::{
    env,
    io::{Read, Write},
    net::{SocketAddr, TcpListener, TcpStream},
    sync::{
        atomic::{AtomicBool, AtomicUsize, Ordering},
        Arc, Mutex,
    },
    thread,
    time::Duration,
};

use durable_workflow::{json, Client, PayloadEnvelope, Value, Worker};

#[derive(Clone)]
struct CodecCase {
    name: &'static str,
    value: Option<Value>,
}

#[derive(Clone, Copy)]
enum ProbePath {
    Workflow,
    Activity,
    Query,
}

impl ProbePath {
    fn all() -> [Self; 3] {
        [Self::Workflow, Self::Activity, Self::Query]
    }

    fn name(self) -> &'static str {
        match self {
            Self::Workflow => "workflow",
            Self::Activity => "activity",
            Self::Query => "query",
        }
    }

    fn poll_path(self) -> &'static str {
        match self {
            Self::Workflow => "/api/worker/workflow-tasks/poll",
            Self::Activity => "/api/worker/activity-tasks/poll",
            Self::Query => "/api/worker/query-tasks/poll",
        }
    }
}

#[derive(Clone)]
struct CapturedRequest {
    path: String,
    body: String,
}

struct ProbeServer {
    addr: SocketAddr,
    stop: Arc<AtomicBool>,
    requests: Arc<Mutex<Vec<CapturedRequest>>>,
    thread: Option<thread::JoinHandle<()>>,
}

impl ProbeServer {
    fn start(path: ProbePath, task: Value) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").expect("bind task codec probe server");
        listener
            .set_nonblocking(true)
            .expect("configure task codec probe server");
        let addr = listener.local_addr().expect("task codec probe address");
        let stop = Arc::new(AtomicBool::new(false));
        let server_stop = Arc::clone(&stop);
        let requests = Arc::new(Mutex::new(Vec::new()));
        let server_requests = Arc::clone(&requests);
        let poll_path = path.poll_path().to_string();
        let task_response = json!({"task": task}).to_string();

        let thread = thread::spawn(move || {
            while !server_stop.load(Ordering::SeqCst) {
                match listener.accept() {
                    Ok((mut stream, _)) => {
                        handle_request(&mut stream, &server_requests, &poll_path, &task_response)
                    }
                    Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                        thread::sleep(Duration::from_millis(2));
                    }
                    Err(_) => break,
                }
            }
        });

        Self {
            addr,
            stop,
            requests,
            thread: Some(thread),
        }
    }

    fn base_url(&self) -> String {
        format!("http://{}", self.addr)
    }

    fn count_suffix(&self, suffix: &str) -> usize {
        self.requests
            .lock()
            .expect("captured task codec requests")
            .iter()
            .filter(|request| request.path.ends_with(suffix))
            .count()
    }

    fn bodies_with_suffix(&self, suffix: &str) -> Vec<String> {
        self.requests
            .lock()
            .expect("captured task codec requests")
            .iter()
            .filter(|request| request.path.ends_with(suffix))
            .map(|request| request.body.clone())
            .collect()
    }
}

impl Drop for ProbeServer {
    fn drop(&mut self) {
        self.stop.store(true, Ordering::SeqCst);
        let _ = TcpStream::connect(self.addr);
        if let Some(thread) = self.thread.take() {
            thread.join().expect("join task codec probe server");
        }
    }
}

fn handle_request(
    stream: &mut TcpStream,
    requests: &Arc<Mutex<Vec<CapturedRequest>>>,
    task_poll_path: &str,
    task_response: &str,
) {
    let _ = stream.set_read_timeout(Some(Duration::from_millis(500)));
    let mut request = Vec::new();
    let mut buffer = [0_u8; 8192];

    loop {
        match stream.read(&mut buffer) {
            Ok(0) => break,
            Ok(read) => {
                request.extend_from_slice(&buffer[..read]);
                if request_is_complete(&request) {
                    break;
                }
            }
            Err(error)
                if matches!(
                    error.kind(),
                    std::io::ErrorKind::WouldBlock | std::io::ErrorKind::TimedOut
                ) =>
            {
                break;
            }
            Err(_) => return,
        }
    }

    let request = String::from_utf8_lossy(&request);
    let path = request
        .lines()
        .next()
        .and_then(|line| line.split_whitespace().nth(1))
        .unwrap_or_default()
        .to_string();
    let body = request
        .split_once("\r\n\r\n")
        .map(|(_, body)| body)
        .unwrap_or_default()
        .to_string();
    let request_number = {
        let mut captured = requests.lock().expect("capture task codec request");
        captured.push(CapturedRequest {
            path: path.clone(),
            body,
        });
        captured
            .iter()
            .filter(|request| request.path == path)
            .count()
    };

    let response = if path == task_poll_path && request_number == 1 {
        task_response
    } else if matches!(
        path.as_str(),
        "/api/worker/workflow-tasks/poll"
            | "/api/worker/activity-tasks/poll"
            | "/api/worker/query-tasks/poll"
    ) {
        r#"{"task":null}"#
    } else if path.ends_with("/complete") || path.ends_with("/fail") {
        r#"{}"#
    } else {
        write_response(stream, "404 Not Found", r#"{"message":"not found"}"#);
        return;
    };

    write_response(stream, "200 OK", response);
}

fn request_is_complete(request: &[u8]) -> bool {
    let Some(header_end) = request.windows(4).position(|window| window == b"\r\n\r\n") else {
        return false;
    };
    let headers = String::from_utf8_lossy(&request[..header_end]);
    let content_length = headers.lines().find_map(|line| {
        let (name, value) = line.split_once(':')?;
        name.eq_ignore_ascii_case("content-length")
            .then(|| value.trim().parse::<usize>().ok())
            .flatten()
    });

    request.len() >= header_end + 4 + content_length.unwrap_or(0)
}

fn write_response(stream: &mut TcpStream, status: &str, body: &str) {
    let response = format!(
        "HTTP/1.1 {status}\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
        body.len(),
    );
    let _ = stream.write_all(response.as_bytes());
    let _ = stream.flush();
}

fn unsupported_cases() -> Vec<CodecCase> {
    vec![
        CodecCase {
            name: "missing",
            value: None,
        },
        CodecCase {
            name: "empty",
            value: Some(json!("")),
        },
        CodecCase {
            name: "json",
            value: Some(json!("json")),
        },
        CodecCase {
            name: "unknown",
            value: Some(json!("custom")),
        },
        CodecCase {
            name: "wrong_case",
            value: Some(json!("Avro")),
        },
        CodecCase {
            name: "null",
            value: Some(Value::Null),
        },
        CodecCase {
            name: "non_string",
            value: Some(json!(["avro"])),
        },
        CodecCase {
            name: "malformed",
            value: Some(json!("avro\0")),
        },
    ]
}

fn task_for(path: ProbePath, codec: &CodecCase) -> Value {
    let arguments =
        PayloadEnvelope::avro(&Vec::<Value>::new()).expect("encode valid Avro task arguments");
    let arguments = json!({"codec": arguments.codec, "blob": arguments.blob});
    let mut task = match path {
        ProbePath::Workflow => json!({
            "task_id": "probe-workflow",
            "workflow_task_attempt": 1,
            "lease_owner": "probe-worker",
            "workflow_id": "probe-workflow-id",
            "run_id": "probe-workflow-run",
            "workflow_type": "probe.workflow",
            "arguments": arguments,
            "history_events": [],
        }),
        ProbePath::Activity => json!({
            "task_id": "probe-activity",
            "activity_attempt_id": "probe-activity-attempt",
            "lease_owner": "probe-worker",
            "activity_type": "probe.activity",
            "arguments": arguments,
        }),
        ProbePath::Query => json!({
            "query_task_id": "probe-query",
            "query_task_attempt": 1,
            "lease_owner": "probe-worker",
            "workflow_id": "probe-query-id",
            "run_id": "probe-query-run",
            "workflow_type": "probe.workflow",
            "query_name": "status",
            "workflow_arguments": arguments,
            "query_arguments": arguments,
            "history_events": [],
        }),
    };
    if let Some(value) = codec.value.clone() {
        task.as_object_mut()
            .expect("probe task object")
            .insert("payload_codec".to_string(), value);
    }
    task
}

fn probe_worker(path: ProbePath, server: &ProbeServer, handler_calls: Arc<AtomicUsize>) -> Worker {
    let client = Client::builder(server.base_url())
        .build()
        .expect("build task codec probe client");
    let mut worker = Worker::new(client, "probe-queue")
        .worker_id("probe-worker")
        .poll_timeout(Duration::from_millis(10));

    match path {
        ProbePath::Workflow => {
            worker.register_workflow("probe.workflow", move |_context, _arguments| {
                handler_calls.fetch_add(1, Ordering::SeqCst);
                async move { Ok(json!("workflow-complete")) }
            });
        }
        ProbePath::Activity => {
            worker.register_activity("probe.activity", move |_context, _arguments| {
                handler_calls.fetch_add(1, Ordering::SeqCst);
                async move { Ok(json!("activity-complete")) }
            });
        }
        ProbePath::Query => {
            worker.register_workflow("probe.workflow", |_context, _arguments| async move {
                Ok(json!("workflow-complete"))
            });
            worker.register_query("probe.workflow", "status", move |_context, _arguments| {
                handler_calls.fetch_add(1, Ordering::SeqCst);
                async move { Ok(json!("query-complete")) }
            });
        }
    }

    worker
}

async fn rejection_outcome(path: ProbePath, codec: &CodecCase) -> Value {
    let server = ProbeServer::start(path, task_for(path, codec));
    let handler_calls = Arc::new(AtomicUsize::new(0));
    let result = probe_worker(path, &server, Arc::clone(&handler_calls))
        .run_once()
        .await;
    let handled_count = result.as_ref().copied().unwrap_or(0);
    let worker_error = result.as_ref().err().map(ToString::to_string);
    let handler_calls = handler_calls.load(Ordering::SeqCst);
    let failures = server.bodies_with_suffix("/fail");
    let failure_count = failures.len();
    let completion_count = server.count_suffix("/complete");
    let unsupported_diagnostic = failures
        .iter()
        .any(|body| body.contains("unsupported_payload_codec"));
    let decode_diagnostic = failures.iter().any(|body| {
        body.contains("invalid_payload_framing") || body.contains("invalid_payload_envelope")
    });
    let passed = result.is_ok()
        && handled_count == 1
        && handler_calls == 0
        && failure_count == 1
        && completion_count == 0
        && unsupported_diagnostic
        && !decode_diagnostic;

    json!({
        "runtime": "rust",
        "worker": "sdk",
        "path": path.name(),
        "codec_case": codec.name,
        "status": if passed { "rejected_before_decode_or_handler" } else { "failed" },
        "handled_count": handled_count,
        "handler_calls": handler_calls,
        "failure_count": failure_count,
        "completion_count": completion_count,
        "unsupported_diagnostic": unsupported_diagnostic,
        "decode_diagnostic": decode_diagnostic,
        "worker_error": worker_error,
    })
}

async fn valid_control(path: ProbePath) -> Value {
    let codec = CodecCase {
        name: "avro",
        value: Some(json!("avro")),
    };
    let server = ProbeServer::start(path, task_for(path, &codec));
    let handler_calls = Arc::new(AtomicUsize::new(0));
    let result = probe_worker(path, &server, Arc::clone(&handler_calls))
        .run_once()
        .await;
    let handled_count = result.as_ref().copied().unwrap_or(0);
    let worker_error = result.as_ref().err().map(ToString::to_string);
    let handler_calls = handler_calls.load(Ordering::SeqCst);
    let failure_count = server.count_suffix("/fail");
    let completion_count = server.count_suffix("/complete");
    let passed = result.is_ok()
        && handled_count == 1
        && handler_calls == 1
        && failure_count == 0
        && completion_count == 1;

    json!({
        "runtime": "rust",
        "worker": "sdk",
        "path": path.name(),
        "codec_case": "avro",
        "status": if passed { "decoded_and_handled" } else { "failed" },
        "handled_count": handled_count,
        "handler_calls": handler_calls,
        "failure_count": failure_count,
        "completion_count": completion_count,
        "worker_error": worker_error,
    })
}

#[tokio::main]
async fn main() {
    let mut rejections = Vec::new();
    for path in ProbePath::all() {
        for codec in unsupported_cases() {
            rejections.push(rejection_outcome(path, &codec).await);
        }
    }
    let mut valid_controls = Vec::new();
    for path in ProbePath::all() {
        valid_controls.push(valid_control(path).await);
    }

    let failed_count = rejections
        .iter()
        .chain(valid_controls.iter())
        .filter(|outcome| outcome.get("status") == Some(&json!("failed")))
        .count();
    let evidence = json!({
        "schema": "durable-workflow.sample-app.task-codec-rejection-probe",
        "version": 1,
        "runtime": "rust",
        "artifact": {
            "name": "durable-workflow",
            "version": env::var("DURABLE_WORKFLOW_RUST_SDK_VERSION").unwrap_or_default(),
        },
        "rejection_outcomes": rejections,
        "valid_controls": valid_controls,
        "summary": {
            "status": if failed_count == 0 { "passed" } else { "failed" },
            "rejection_count": rejections.len(),
            "valid_control_count": valid_controls.len(),
            "failed_count": failed_count,
        },
    });

    println!("{evidence}");
    if failed_count != 0 {
        std::process::exit(1);
    }
}
