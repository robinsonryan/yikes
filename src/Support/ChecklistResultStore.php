<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Support;

use RobinsonRyan\Yikes\Data\Checklist;
use RobinsonRyan\Yikes\Data\ChecklistTest;
use RobinsonRyan\Yikes\Enums\StepStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Per-tester checklist results, stored as flat JSON under the yikes store
 * (`<yikes-path>/checklists/<tester>/<suite>.json`) so the same persistent
 * volume + pull workflow that protects notes also covers test progress.
 *
 * Shape:
 *   { tester, suite, updated_at,
 *     tests: { <test-slug>: { steps: { "<1-based-index>": {
 *       status: pass|fail, reason, note_id, recorded_at } } } } }
 */
final class ChecklistResultStore
{
    private const string SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/D';

    public function __construct(private readonly string $basePath) {}

    /**
     * Raw results for one tester + suite (empty structure when none yet).
     *
     * @return array{tester: string, suite: string, updated_at: string|null, tests: array<string, array{steps: array<string, array<string, mixed>>}>}
     */
    public function results(string $tester, string $suite): array
    {
        $this->assertSlug($tester);
        $this->assertSlug($suite);

        $file = $this->file($tester, $suite);

        if (! is_file($file)) {
            return ['tester' => $tester, 'suite' => $suite, 'updated_at' => null, 'tests' => []];
        }

        $contents = file_get_contents($file);

        /** @var array<string, mixed>|null $data */
        $data = $contents === false ? null : json_decode($contents, true);

        if (! is_array($data)) {
            return ['tester' => $tester, 'suite' => $suite, 'updated_at' => null, 'tests' => []];
        }

        return [
            'tester' => $tester,
            'suite' => $suite,
            'updated_at' => is_string($data['updated_at'] ?? null) ? $data['updated_at'] : null,
            'tests' => is_array($data['tests'] ?? null) ? $data['tests'] : [],
        ];
    }

    /**
     * Record one step outcome; fail steps carry the reason and (optionally)
     * the id of the yikes note spawned for it.
     */
    public function recordStep(
        string $tester,
        string $suite,
        string $test,
        int $step,
        StepStatus $status,
        ?string $reason = null,
        ?string $noteId = null,
    ): void {
        $this->assertSlug($test);

        $results = $this->results($tester, $suite);

        $results['tests'][$test]['steps'][(string) $step] = [
            'status' => $status->value,
            'reason' => $reason,
            'note_id' => $noteId,
            'recorded_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        $this->write($tester, $suite, $results);
    }

    /**
     * Clear one test's recorded steps (the "re-run after a fix" action).
     */
    public function resetTest(string $tester, string $suite, string $test): void
    {
        $this->assertSlug($test);

        $results = $this->results($tester, $suite);

        unset($results['tests'][$test]);

        $this->write($tester, $suite, $results);
    }

    /**
     * Roll a suite definition + one tester's results up into per-test and
     * suite-level statuses: passed | failed | in-progress | pending.
     *
     * @return array{status: string, tests: array<string, array{status: string, passed: int, failed: int, total: int}>}
     */
    public function summarize(Checklist $checklist, string $tester): array
    {
        $results = $this->results($tester, $checklist->slug);

        $tests = [];

        foreach ($checklist->tests as $test) {
            $tests[$test->slug] = $this->summarizeTest($test, $results['tests'][$test->slug]['steps'] ?? []);
        }

        $statuses = array_column($tests, 'status');

        $status = match (true) {
            in_array('failed', $statuses, true) => 'failed',
            $statuses !== [] && array_unique($statuses) === ['passed'] => 'passed',
            in_array('in-progress', $statuses, true) || in_array('passed', $statuses, true) => 'in-progress',
            default => 'pending',
        };

        return ['status' => $status, 'tests' => $tests];
    }

    /**
     * @param  array<string, array<string, mixed>>  $steps
     * @return array{status: string, passed: int, failed: int, total: int}
     */
    private function summarizeTest(ChecklistTest $test, array $steps): array
    {
        $total = count($test->steps);
        $passed = 0;
        $failed = 0;

        foreach (range(1, $total) as $index) {
            $status = $steps[(string) $index]['status'] ?? null;

            if ($status === StepStatus::Pass->value) {
                $passed++;
            } elseif ($status === StepStatus::Fail->value) {
                $failed++;
            }
        }

        $status = match (true) {
            $failed > 0 => 'failed',
            $passed === $total => 'passed',
            $passed > 0 => 'in-progress',
            default => 'pending',
        };

        return ['status' => $status, 'passed' => $passed, 'failed' => $failed, 'total' => $total];
    }

    /**
     * @param  array<string, mixed>  $results
     */
    private function write(string $tester, string $suite, array $results): void
    {
        $results['updated_at'] = CarbonImmutable::now()->toIso8601String();

        $dir = $this->basePath . '/checklists/' . $tester;

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create checklist results directory [{$dir}].");
        }

        file_put_contents(
            $this->file($tester, $suite),
            json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    private function file(string $tester, string $suite): string
    {
        return $this->basePath . '/checklists/' . $tester . '/' . $suite . '.json';
    }

    private function assertSlug(string $value): void
    {
        if (preg_match(self::SLUG_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid checklist slug [{$value}].");
        }
    }
}
