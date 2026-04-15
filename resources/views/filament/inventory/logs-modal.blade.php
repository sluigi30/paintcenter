<div class="space-y-3 p-1">
    @forelse ($logs as $log)
        <div class="flex items-start justify-between rounded-lg border border-gray-100 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-col gap-0.5">

                {{-- Action badge --}}
                <span class="inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-semibold
                    {{ match($log->action_name) {
                        'restock'      => 'bg-green-100 text-green-700',
                        'deduct'       => 'bg-red-100 text-red-700',
                        'order_deduct' => 'bg-yellow-100 text-yellow-700',
                        default        => 'bg-blue-100 text-blue-700',
                    } }}">
                    {{ $log->action_label }}
                </span>

                {{-- Notes --}}
                @if ($log->notes)
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $log->notes }}</p>
                @endif

                {{-- Admin + Date --}}
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ $log->admin?->first_name ?? 'System' }}
                    &bull;
                    {{ $log->created_at->format('M d, Y h:i A') }}
                </p>
            </div>

            {{-- Quantity --}}
            <span class="ml-4 text-base font-bold
                {{ $log->quantity_changed > 0 ? 'text-green-600' : 'text-red-500' }}">
                {{ $log->formatted_quantity }}
            </span>
        </div>
    @empty
        <p class="py-6 text-center text-sm text-gray-400">No stock adjustments recorded yet.</p>
    @endforelse
</div>