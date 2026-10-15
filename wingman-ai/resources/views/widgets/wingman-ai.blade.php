<x-filament::widget>
    @assets
        <script>{!! \Pelican\AiHelpAssistant\Services\StartupMonitorService::script() !!}</script>
    @endassets

    <x-filament::section>
        <x-slot name="heading">Wingman AI</x-slot>
        <x-slot name="description">Understand a failed start and get practical steps to fix it.</x-slot>

        <div wire:poll.10s="refreshAutomaticDiagnosis" class="mb-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700" role="status" aria-live="polite">
            <p class="text-sm font-semibold text-gray-950 dark:text-white">Automatic startup diagnosis</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $this->automaticStatusText() }}</p>
            @if ($autoReport && $report)
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Latest automatic result: {{ $autoReport['summary'] ?? 'Diagnosis available' }}
                    ({{ $this->automaticReportTime() }}). Your manual diagnosis is shown below.
                </p>
            @endif
        </div>

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
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300" data-ai-status>Start &amp; Diagnose opens an optional manual capture. Automatic startup checks run in the background when enabled.</p>
            </div>
        </div>

        @php
            $displayReport = $report ?? $autoReport;
            $isAutomaticReport = $report === null && $autoReport !== null;
        @endphp

        @if ($displayReport)
            <div class="mt-5 space-y-4 border-t border-gray-200 pt-5 dark:border-gray-700" aria-label="Diagnostic report">
                @if ($isAutomaticReport)
                    <p class="text-xs font-medium text-primary-600 dark:text-primary-400">
                        {{ ($autoStatus['status'] ?? '') === 'monitoring' ? 'Previous automatic diagnosis' : 'Automatic diagnosis' }} &middot;
                        <time datetime="{{ $autoReport['generated_at'] ?? '' }}">{{ $this->automaticReportTime() }}</time>
                    </p>
                @endif
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $displayReport['summary'] ?? 'Diagnosis' }}</h3>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ ($displayReport['source'] ?? 'rules') === 'ai' ? 'AI assisted' : 'Built-in checks' }} &middot; {{ ucfirst($displayReport['confidence'] ?? 'low') }} confidence</span>
                    </div>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $displayReport['likely_cause'] ?? '' }}</p>
                </div>

                @if (!empty($displayReport['notice']))
                    <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-900 dark:text-gray-400">{{ $displayReport['notice'] }}</p>
                @endif

                @if (!empty($displayReport['steps']))
                    <div>
                        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Recommended next steps</h4>
                        <ol class="mt-2 list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($displayReport['steps'] as $step)
                                <li class="whitespace-pre-wrap">{{ $step }}</li>
                            @endforeach
                        </ol>
                    </div>
                @endif

                @php($fixOffer = $this->fixOffer())
                @if ($fixOffer)
                    <div class="rounded-lg p-3" style="border: 1px solid rgba(59, 130, 246, 0.45); background: rgba(59, 130, 246, 0.08);">
                        <p class="text-sm font-semibold text-gray-950 dark:text-white">Wingman can fix this</p>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $this->fixSummary($fixOffer) }} You will be asked to confirm first.</p>
                        <div class="mt-3">{{ $this->applyFixAction }}</div>
                    </div>
                @endif

                @if (!empty($displayReport['evidence']))
                    <details class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <summary class="cursor-pointer text-sm font-medium text-gray-950 dark:text-white">Evidence from this capture</summary>
                        <ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-400">
                            @foreach ($displayReport['evidence'] as $evidence)
                                <li class="break-words whitespace-pre-wrap font-mono">{{ $evidence }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                @if (!empty($displayReport['needs_more_information']))
                    <div>
                        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">What to check next</h4>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($displayReport['needs_more_information'] as $item)
                                <li class="whitespace-pre-wrap">{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        @php($eggTools = $this->eggTools())
        @if ($eggTools)
            {{-- Inline styles keep this readable even when the panel has not rebuilt its CSS for plugins. --}}
            <div style="margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid rgba(127, 127, 127, 0.25);" aria-label="Egg check">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-950 dark:text-white">Egg check <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(admins only)</span></p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Ask the AI whether this server's egg is causing the problem. It reads the egg, the install log, and recent console output, then suggests a change. Nothing changes until you review and apply it.</p>
                    </div>
                    <x-filament::button color="gray" wire:click="checkEgg" wire:loading.attr="disabled" wire:target="checkEgg">
                        <span wire:loading.remove wire:target="checkEgg">Ask AI to check the egg</span>
                        <span wire:loading wire:target="checkEgg">Checking… this can take up to a minute</span>
                    </x-filament::button>
                </div>

                @if ($eggTools['last_change'])
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-900 dark:text-gray-300">
                        <span>
                            Wingman changed this egg on <time datetime="{{ $eggTools['last_change']['applied_at'] }}">{{ \Illuminate\Support\Carbon::parse($eggTools['last_change']['applied_at'])->format('M j, Y H:i') }} UTC</time>
                            ({{ implode(', ', array_map(fn ($field) => str_replace('_', ' ', $field), $eggTools['last_change']['fields'])) }}).
                            @unless ($eggTools['last_change']['undoable'])
                                It was edited again since, so it cannot be undone automatically.
                            @endunless
                        </span>
                        @if ($eggTools['last_change']['undoable'])
                            {{ $this->undoEggFixAction }}
                        @endif
                    </div>
                @endif

                @if ($eggProposal)
                    <div class="mt-3 rounded-lg p-3" style="border: 1px solid rgba(127, 127, 127, 0.3);">
                        <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $eggProposal['summary'] }}</p>
                        @if ($eggProposal['reason'] !== '')
                            <p class="mt-1 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{{ $eggProposal['reason'] }}</p>
                        @endif

                        @if ($eggProposal['changes'] === [])
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">The AI did not find a problem in the egg, so there is nothing to apply.</p>
                        @else
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                Suggested by AI; review every change before applying. The "{{ $eggProposal['egg_name'] }}" egg is used by {{ $eggProposal['servers_using'] }} {{ $eggProposal['servers_using'] === 1 ? 'server' : 'servers' }}.
                                @if ($eggProposal['copies_script'])
                                    This egg currently uses another egg's install script; applying gives it its own copy.
                                @endif
                            </p>

                            @if ($eggProposal['warnings'] !== [])
                                <div class="mt-2 rounded-lg p-3 text-sm" style="border: 1px solid rgba(245, 158, 11, 0.55); background: rgba(245, 158, 11, 0.1);" role="alert">
                                    <p class="font-semibold">Check these carefully before applying:</p>
                                    @foreach ($eggProposal['warnings'] as $warning)
                                        <p class="mt-1">&bull; {{ $warning }}</p>
                                    @endforeach
                                </div>
                            @endif

                            @foreach ($eggProposal['changes'] as $change)
                                <div class="mt-3">
                                    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $change['label'] }}</p>
                                    @if (isset($change['diff']))
                                        <div class="mt-1 overflow-auto rounded-lg font-mono text-xs" style="max-height: 24rem; border: 1px solid rgba(127, 127, 127, 0.3);">
                                            @foreach ($change['diff'] as [$type, $line])
                                                <div style="white-space: pre-wrap; word-break: break-all; padding: 0 0.5rem; {{ $type === '+' ? 'background: rgba(34, 197, 94, 0.18);' : ($type === '-' ? 'background: rgba(239, 68, 68, 0.18);' : ($type === '…' ? 'opacity: 0.6; font-style: italic;' : '')) }}">{{ $type === '…' ? '… ' : $type . ' ' }}{{ $line }}</div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="mt-1 font-mono text-xs" style="white-space: pre-wrap; word-break: break-all;">
                                            <div style="padding: 0.25rem 0.5rem; background: rgba(239, 68, 68, 0.18);">- {{ $change['before'] }}</div>
                                            <div style="padding: 0.25rem 0.5rem; background: rgba(34, 197, 94, 0.18);">+ {{ $change['after'] }}</div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        @endif

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            {{ $this->applyEggFixAction }}
                            <x-filament::button color="gray" wire:click="dismissEggProposal">Dismiss</x-filament::button>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />

    @script
        <script>
            const assistantController = window.PelicanAiHelpAssistant.mount($wire.$el, $wire);
            document.addEventListener('livewire:navigating', () => assistantController.destroy(), { once: true });
        </script>
    @endscript
</x-filament::widget>
