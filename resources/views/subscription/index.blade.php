<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Subscription Plans') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach ($plans as $plan)
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-semibold text-gray-900">{{ $plan['name'] }}</h3>
                            <p class="mt-4 text-3xl font-bold text-gray-900">₱{{ number_format($plan['price'], 2) }}</p>
                            <p class="mt-1 text-sm text-gray-500">per month</p>
                            
                            <ul class="mt-6 space-y-4">
                                @foreach ($plan['features'] as $feature)
                                    <li class="flex items-start">
                                        <svg class="h-6 w-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                        <span class="ml-3 text-sm text-gray-700">{{ $feature }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            <form action="{{ route('subscription.create') }}" method="POST" class="mt-8" id="subscription-form-{{ $plan['id'] }}">
                                @csrf
                                <input type="hidden" name="plan_id" value="{{ $plan['id'] }}">
                                <button type="button" onclick="confirmSubscription('{{ $plan['id'] }}', '{{ $plan['name'] }}', {{ $plan['price'] }})" class="w-full inline-flex justify-center items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                    Subscribe Now
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout> 