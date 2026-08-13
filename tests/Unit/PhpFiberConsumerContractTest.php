<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PhpFiberConsumerContractTest extends TestCase
{
    private const string GENERATOR_TYPE_PATTERN = '/(?<![A-Za-z0-9_\\\\])\\\\?Generator\b/i';

    #[DataProvider('serviceModePhpSourceProvider')]
    public function test_service_mode_workflows_reject_generator_syntax(string $path): void
    {
        $source = (string) file_get_contents($path);
        $relativePath = str_replace(dirname(__DIR__, 2).'/', '', $path);

        $this->assertDoesNotMatchRegularExpression(
            self::GENERATOR_TYPE_PATTERN,
            $source,
            "{$relativePath} must use ordinary Fiber workflow return values.",
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\byield(?:\s+from)?\s+\$[A-Za-z_][A-Za-z0-9_]*->/',
            $source,
            "{$relativePath} must call WorkflowContext operations directly.",
        );
    }

    #[DataProvider('builtinGeneratorTypeProvider')]
    public function test_generator_guard_covers_builtin_type_spellings(string $returnType): void
    {
        $this->assertMatchesRegularExpression(
            self::GENERATOR_TYPE_PATTERN,
            "function workflow(): {$returnType} {}",
        );
    }

    /** @return array<string, array{string}> */
    public static function builtinGeneratorTypeProvider(): array
    {
        return [
            'unqualified' => ['Generator'],
            'fully qualified' => ['\\Generator'],
            'case-insensitive unqualified' => ['gEnErAtOr'],
            'case-insensitive fully qualified' => ['\\gEnErAtOr'],
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function serviceModePhpSourceProvider(): iterable
    {
        $repoRoot = dirname(__DIR__, 2);
        $paths = [$repoRoot.'/polyglot/php_worker/worker.php'];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repoRoot.'/app/Workflows/ServiceMode'),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);
        foreach ($paths as $path) {
            $relativePath = str_replace($repoRoot.'/', '', $path);
            yield $relativePath => [$path];
        }
    }
}
