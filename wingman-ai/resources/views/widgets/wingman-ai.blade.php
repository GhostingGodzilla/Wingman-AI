<x-filament::widget>
    @assets
        <script>{!! \Pelican\AiHelpAssistant\Services\StartupMonitorService::script() !!}</script>
    @endassets

    <x-filament::section>
        <x-slot name="heading">Wingman AI</x-slot>
        <x-slot name="description">Understand a failed start and get practical steps to fix it.</x-slot>

        <div wire:ignore class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Start a stopped server and watch its console, or observe a server that is already active.
                The assistant uses recent console output and permitted server settings to explain what it finds.
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                @if ($this->isAiConfigured())
                    Redacted diagnostic data may be sent to the AI provider configured by your panel administrator.
                    Suggested fixes require your review.
                @else
                    Basic error checks are available. An administrator can connect an AI model in plugin settings.
                @endif
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="button" data-ai-start>Start &amp; Diagnose</x-filament::button>
                <x-filament::button type="button" color="gray" data-ai-existing>Analyze existing logs</x-filament::button>
                <x-filament::button type="button" color="gray" data-ai-cancel hidden>Cancel capture</x-filament::button>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900" role="status" aria-live="polite" aria-atomic="true">
                <p class="text-xs font-semibold text-primary-600 dark:text-primary-400" data-ai-badge>Ready</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300" data-ai-status>Click Start &amp; Diagnose when you want help. Nothing runs automatically.</p>
            </div>
        </div>

        @if ($report)
            <div class="mt-5 space-y-4 border-t border-gray-200 pt-5 dark:border-gray-700" aria-label="Diagnostic report">
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $report['summary'] ?? 'Diagnosis' }}</h3>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ ($report['source'] ?? 'rules') === 'ai' ? 'AI assisted' : 'Built-in checks' }} · {{ ucfirst($report['confidence'] ?? 'low') }} confidence</span>
                    </div>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $report['likely_cause'] ?? '' }}</p>
                </div>

                @if (!empty($report['notice']))
                    <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-900 dark:text-gray-400">{{ $report['notice'] }}</p>
                @endif

                @if (!empty($report['steps']))
                    <div>
                        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Recommended next steps</h4>
                        <ol class="mt-2 list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($report['steps'] as $step)
                                <li class="whitespace-pre-wrap">{{ $step }}</li>
                            @endforeach
                        </ol>
                    </div>
                @endif

                @if (!empty($report['evidence']))
                    <details class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <summary class="cursor-pointer text-sm font-medium text-gray-950 dark:text-white">Evidence from this capture</summary>
                        <ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-400">
                            @foreach ($report['evidence'] as $evidence)
                                <li class="break-words whitespace-pre-wrap font-mono">{{ $evidence }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                @if (!empty($report['needs_more_information']))
                    <div>
                        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">What to check next</h4>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($report['needs_more_information'] as $item)
                                <li class="whitespace-pre-wrap">{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>

    @script
        <script>
            const assistantController = window.PelicanAiHelpAssistant.mount($wire.$el, $wire);
            document.addEventListener('livewire:navigating', () => assistantController.destroy(), { once: true });
        </script>
    @endscript
</x-filament::widget>
