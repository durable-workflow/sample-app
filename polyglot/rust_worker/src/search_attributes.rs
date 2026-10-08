use std::time::Duration;

use durable_workflow::{json, Client, Result, SearchAttributeUpdate, Worker};

pub async fn worker(client: Client) -> Result<()> {
    let mut worker = Worker::new(client, "rust-search-attributes")
        .worker_id("rust-search-attributes-worker")
        .poll_timeout(Duration::from_secs(3));
    worker.register_workflow("sample-app.rust.search-attributes", |ctx, input| async move {
        let initial = SearchAttributeUpdate::new()
            .string("SearchText", format!("{}ab", "界".repeat(682)))?
            .keyword("SearchKeyword", "界".repeat(85))?
            .keyword_list("SearchTags", [" urgent ".to_string(), "界".repeat(85), "urgent".to_string()])?
            .int("SearchCount", 9_007_199_254_740_993)?
            .float("SearchRatio", 1.25)?
            .bool("SearchFlag", true)?
            .datetime("SearchTime", "2026-10-08T12:34:56.123456Z")?
            .keyword("SearchRemoved", "erase")?;
        ctx.upsert_search_attributes(initial)?;
        let signal = ctx.wait_signal("search-release").await?;
        ctx.upsert_search_attributes(
            SearchAttributeUpdate::new()
                .string("SearchText", "finished café 界")?
                .keyword("SearchKeyword", "finished")?
                .keyword_list("SearchTags", ["done", "café"])?
                .bool("SearchFlag", false)?
                .delete("SearchRemoved")?,
        )?;
        Ok(json!({"workflow_runtime":"rust", "request":super::first_argument(&input), "signal":signal}))
    });
    worker.declare_workflow_signals("sample-app.rust.search-attributes", &["search-release"])?;
    worker.run().await
}
