# V1 to V2 Workflow Migration Guide

This guide shows how to migrate from the v1 generator-based workflow API to the v2 fiber-based API.

## Quick Comparison

| Aspect | V1 (Generator) | V2 (Fiber) |
|--------|----------------|------------|
| Base class | `Workflow\Workflow` | `Workflow\V2\Workflow` |
| Activity helper | `use function Workflow\activity;` | `use function Workflow\V2\activity;` |
| Activity calls | `yield activity(...)` | `activity(...)` |
| Entry method | `execute()` | `handle()` or `execute()` |
| Activity base | `extends Workflow\Activity` | `#[Activity]` attribute |

## Example: Simple Workflow

### V1 (Generator-based)

```php
namespace App\Workflows\Simple;

use Workflow\Workflow;
use function Workflow\activity;

class SimpleWorkflow extends Workflow
{
    public function execute()
    {
        $result = yield activity(SimpleActivity::class);
        $otherResult = yield activity(SimpleOtherActivity::class, 'other');
        return 'workflow_' . $result . '_' . $otherResult;
    }
}
```

### V2 (Fiber-based)

```php
namespace App\Workflows\SimpleV2;

use Workflow\V2\Workflow;
use function Workflow\V2\activity;

class SimpleWorkflowV2 extends Workflow
{
    public function handle(): string
    {
        // No yield — activity() suspends and returns directly
        $result = activity(SimpleActivityV2::class);
        $otherResult = activity(SimpleOtherActivityV2::class, 'other');
        return 'workflow_' . $result . '_' . $otherResult;
    }
}
```

## Activity Migration

### V1 Activity

```php
use Workflow\Activity;

class SimpleActivity extends Activity
{
    public function execute()
    {
        return 'activity';
    }
}
```

### V2 Activity

```php
use Workflow\V2\Attributes\Activity;

#[Activity(name: 'simple-activity-v2')]
class SimpleActivityV2
{
    public function execute(): string
    {
        return 'activity';
    }
}
```

## Step-by-Step Migration Checklist

1. **Change workflow base class**
   ```php
   - use Workflow\Workflow;
   + use Workflow\V2\Workflow;
   ```

2. **Update activity helper import**
   ```php
   - use function Workflow\activity;
   + use function Workflow\V2\activity;
   ```

3. **Remove yield from activity calls**
   ```php
   - $result = yield activity(...);
   + $result = activity(...);
   ```

4. **Rename entry method (optional)**
   ```php
   - public function execute()
   + public function handle()
   ```

5. **Update activities to use attributes**
   ```php
   - use Workflow\Activity;
   - class MyActivity extends Activity
   + use Workflow\V2\Attributes\Activity;
   + #[Activity(name: 'my-activity')]
   + class MyActivity
   ```

6. **Add type hints (recommended)**
   ```php
   public function handle(string $input): string
   ```

## Running V2 Workflows

V2 workflows run on the standalone server or cloud platform. The server uses the v2 engine under `Workflow\V2\`.

### Start a V2 workflow via CLI

```bash
php artisan workflow:start SimpleWorkflowV2 --input='[]'
```

### Start a V2 workflow via HTTP API

```bash
curl -X POST http://localhost:8080/api/workflows \
  -H "Authorization: Bearer your-token" \
  -H "X-Namespace: default" \
  -H "Content-Type: application/json" \
  -d '{
    "workflow_type": "SimpleWorkflowV2",
    "workflow_id": "simple-1",
    "task_queue": "default",
    "input": []
  }'
```

## Why Migrate?

- **Better performance**: Fibers are lighter than generators
- **Cleaner code**: No yield boilerplate
- **Type safety**: Full type hints work correctly
- **Production ready**: V2 is the actively developed API
- **Cloud support**: Cloud platform only supports v2
- **Polyglot**: V2 protocol works with Python SDK and other languages

## Both APIs Work

You can run v1 and v2 workflows side-by-side in the same application. The server detects the workflow version and uses the appropriate engine. Migrate incrementally at your own pace.
