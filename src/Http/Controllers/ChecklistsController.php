<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Http\Controllers;

use RobinsonRyan\Yikes\Data\Checklist;
use RobinsonRyan\Yikes\Data\ChecklistTest;
use RobinsonRyan\Yikes\Data\Tester;
use RobinsonRyan\Yikes\Enums\NoteStatus;
use RobinsonRyan\Yikes\Enums\NoteType;
use RobinsonRyan\Yikes\Enums\StepStatus;
use RobinsonRyan\Yikes\Http\Requests\RecordStepRequest;
use RobinsonRyan\Yikes\Support\ChecklistRepository;
use RobinsonRyan\Yikes\Support\ChecklistResultStore;
use RobinsonRyan\Yikes\Support\NoteRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tester-facing UAT checklist surface (`/testing/...`). Public within
 * the yikes gate (staging sits behind an access proxy): testers see their
 * credentials and walk suites step by step; a failed step spawns a yikes
 * note carrying the checklist context.
 */
final class ChecklistsController
{
    public function __construct(
        private readonly ChecklistRepository $checklists,
        private readonly ChecklistResultStore $results,
        private readonly NoteRepository $notes,
    ) {}

    /**
     * Tester picker.
     */
    public function index(Request $request): View|JsonResponse
    {
        return $this->page($request, 'testing/Index', 'Test Checklists', [
            'testers' => $this->checklists->testers()
                ->map(static fn (Tester $tester): array => [
                    'slug' => $tester->slug,
                    'name' => $tester->name,
                ])
                ->all(),
        ]);
    }

    /**
     * One tester's landing page: credentials + suite list with roll-ups.
     */
    public function tester(Request $request, string $tester): View|JsonResponse
    {
        $testerData = $this->findTesterOrFail($tester);

        return $this->page($request, 'testing/Tester', 'Checklists — ' . $testerData->name, [
            'tester' => $this->presentTester($testerData),
            'roles' => $this->checklists->roles(),
            'roleReferenceUrl' => $this->checklists->roleReferenceUrl(),
            'suites' => $this->checklists->all()
                ->map(fn (Checklist $checklist): array => [
                    'slug' => $checklist->slug,
                    'title' => $checklist->title,
                    'description' => $this->personalize($checklist->description, $testerData),
                    'summary' => $this->results->summarize($checklist, $testerData->slug),
                ])
                ->all(),
        ]);
    }

    /**
     * One suite: its tests with per-test status.
     */
    public function suite(Request $request, string $tester, string $suite): View|JsonResponse
    {
        $testerData = $this->findTesterOrFail($tester);
        $checklist = $this->findChecklistOrFail($suite);

        $summary = $this->results->summarize($checklist, $testerData->slug);

        return $this->page($request, 'testing/Suite', $checklist->title . ' — ' . $testerData->name, [
            'tester' => ['slug' => $testerData->slug, 'name' => $testerData->name],
            'suite' => $this->personalize($checklist->toArray(), $testerData),
            'summary' => $summary,
        ]);
    }

    /**
     * One test: steps with recorded outcomes and pass/fail controls.
     */
    public function test(Request $request, string $tester, string $suite, string $test): View|JsonResponse
    {
        $testerData = $this->findTesterOrFail($tester);
        $checklist = $this->findChecklistOrFail($suite);
        $testData = $checklist->findTest($test);

        abort_unless($testData instanceof ChecklistTest, 404);

        $results = $this->results->results($testerData->slug, $checklist->slug);

        return $this->page($request, 'testing/Test', $testData->title . ' — ' . $testerData->name, [
            'tester' => ['slug' => $testerData->slug, 'name' => $testerData->name],
            'suite' => ['slug' => $checklist->slug, 'title' => $checklist->title],
            'test' => $this->personalize($testData->toArray(), $testerData),
            'results' => $results['tests'][$testData->slug]['steps'] ?? (object) [],
        ]);
    }

    /**
     * Record one step outcome. A fail spawns a yikes note (type bug,
     * status new) pre-loaded with the checklist context and the tester's
     * reason, and the note id is stored with the result.
     */
    public function recordStep(RecordStepRequest $request, string $tester, string $suite, string $test): JsonResponse
    {
        $testerData = $this->findTesterOrFail($tester);
        $checklist = $this->findChecklistOrFail($suite);
        $testData = $checklist->findTest($test);

        abort_unless($testData instanceof ChecklistTest, 404);

        $step = (int) $request->validated('step');

        abort_unless($step >= 1 && $step <= count($testData->steps), 422);

        $status = StepStatus::from((string) $request->validated('status'));
        $reason = $request->validated('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        $noteId = null;

        if ($status === StepStatus::Fail) {
            $noteId = $this->createFailureNote($testerData, $checklist, $testData, $step, $reason ?? '(no reason given)');
        }

        $this->results->recordStep($testerData->slug, $checklist->slug, $testData->slug, $step, $status, $reason, $noteId);

        return response()->json(['message' => $status === StepStatus::Pass
            ? 'Step ' . $step . ' passed.'
            : 'Step ' . $step . ' failed — a yikes note was filed.']);
    }

    /**
     * Clear one test's results so it can be re-run after a fix.
     */
    public function resetTest(string $tester, string $suite, string $test): JsonResponse
    {
        $testerData = $this->findTesterOrFail($tester);
        $checklist = $this->findChecklistOrFail($suite);

        abort_unless($checklist->findTest($test) instanceof ChecklistTest, 404);

        $this->results->resetTest($testerData->slug, $checklist->slug, $test);

        return response()->json(['message' => 'Test reset — run it again.']);
    }

    /**
     * Render an island page (Blade shell) or, for the island's own refetches,
     * the raw props as JSON.
     *
     * @param  array<string, mixed>  $props
     */
    private function page(Request $request, string $component, string $title, array $props): View|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json($props);
        }

        return view('yikes::page', [
            'title' => $title,
            'component' => $component,
            'props' => $props,
        ]);
    }

    private function createFailureNote(
        Tester $tester,
        Checklist $checklist,
        ChecklistTest $test,
        int $step,
        string $reason,
    ): string {
        $stepText = $test->steps[$step - 1] ?? '';

        $note = $this->notes->create(
            body: $reason . "\n\n> Step " . $step . ' of ' . count($test->steps) . ": {$stepText}",
            title: 'UAT fail: ' . $checklist->title . ' / ' . $test->title . ' — step ' . $step,
            type: NoteType::Bug,
            context: [
                'checklist' => [
                    'suite' => $checklist->slug,
                    'test' => $test->slug,
                    'step' => $step,
                    'tester' => $tester->slug,
                ],
            ],
            createdBy: ['name' => $tester->name, 'email' => $tester->primaryEmail()],
            stateJson: null,
            status: NoteStatus::New,
        );

        return $note->id;
    }

    /**
     * The tester payload for the landing page: credentials augmented with a
     * one-click auto-login URL when the host app configured a template
     * (`yikes.checklists.login_url`, `{email}` placeholder).
     *
     * @return array<string, mixed>
     */
    private function presentTester(Tester $tester): array
    {
        $template = config('yikes.checklists.login_url');
        $template = is_string($template) && $template !== '' ? $template : null;

        $data = $tester->toArray();

        $data['credentials'] = array_map(
            static fn (array $credential): array => [
                ...$credential,
                'login_url' => $template !== null
                    ? str_replace('{email}', urlencode((string) $credential['email']), $template)
                    : null,
            ],
            is_array($data['credentials']) ? $data['credentials'] : [],
        );

        return $data;
    }

    /**
     * Replace `{first}` tokens in authored checklist text with the tester's
     * slug, so instructions name the tester's own logins (e.g.
     * "{first}.admin@testaccount.example" → "andrea.admin@testaccount.example").
     * Walks arrays recursively; leaves keys and non-strings untouched.
     *
     * @template T of array<array-key, mixed>|string|null
     *
     * @param  T  $value
     * @return T
     */
    private function personalize(array|string|null $value, Tester $tester): array|string|null
    {
        if (is_string($value)) {
            return str_replace('{first}', $tester->slug, $value);
        }

        if (is_array($value)) {
            return array_map(
                fn (mixed $item): mixed => is_string($item) || is_array($item)
                    ? $this->personalize($item, $tester)
                    : $item,
                $value,
            );
        }

        return $value;
    }

    private function findTesterOrFail(string $slug): Tester
    {
        // Tolerate "/testing/Andrea" — tester slugs are lowercase.
        $tester = $this->checklists->findTester(strtolower($slug));

        abort_unless($tester instanceof Tester, 404);

        return $tester;
    }

    private function findChecklistOrFail(string $slug): Checklist
    {
        $checklist = $this->checklists->find($slug);

        abort_unless($checklist instanceof Checklist, 404);

        return $checklist;
    }
}
