<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Activity Log') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('View your account activity and recent actions.') }}
        </p>
    </header>

    <div class="mt-6">
        <!-- Filters -->
        <div class="mb-6 flex flex-col sm:flex-row gap-4">
            <div class="flex-1">
                <label for="action-filter" class="block text-sm font-medium text-gray-700 mb-1">Filter by Action</label>
                <select id="action-filter" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    <option value="">All Actions</option>
                    <option value="login">Login</option>
                    <option value="logout">Logout</option>
                    <option value="profile_update">Profile Update</option>
                    <option value="password_change">Password Change</option>
                    <option value="subscription_created">Subscription Created</option>
                    <option value="payment_made">Payment Made</option>
                    <option value="organizer_status_changed">Organizer Status Changed</option>
                </select>
            </div>
            
            <div class="flex-1">
                <label for="date-filter" class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                <select id="date-filter" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    <option value="7">Last 7 days</option>
                    <option value="30" selected>Last 30 days</option>
                    <option value="90">Last 90 days</option>
                    <option value="365">Last year</option>
                    <option value="all">All time</option>
                </select>
            </div>
        </div>

        <!-- Activity Log Table -->
        <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
            <table class="min-w-full divide-y divide-gray-300">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Action
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Description
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            IP Address
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Date & Time
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="activity-log-tbody">
                    @forelse(auth()->user()->activityLogs()->latest()->take(50)->get() as $activity)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                    @if($activity->action === 'login') bg-green-100 text-green-800
                                    @elseif($activity->action === 'logout') bg-gray-100 text-gray-800
                                    @elseif($activity->action === 'profile_update') bg-blue-100 text-blue-800
                                    @elseif($activity->action === 'password_change') bg-yellow-100 text-yellow-800
                                    @elseif($activity->action === 'subscription_created') bg-purple-100 text-purple-800
                                    @elseif($activity->action === 'payment_made') bg-indigo-100 text-indigo-800
                                    @else bg-gray-100 text-gray-800
                                    @endif">
                                    {{ ucwords(str_replace('_', ' ', $activity->action)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900">{{ $activity->description ?: 'No description available' }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $activity->ip_address ?: 'N/A' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $activity->created_at->format('M d, Y H:i') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">
                                No activity logs found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(auth()->user()->activityLogs()->count() > 50)
            <div class="mt-4 text-center">
                <p class="text-sm text-gray-500">Showing the most recent 50 activities. 
                    <a href="#" class="text-indigo-600 hover:text-indigo-500">View all activities</a>
                </p>
            </div>
        @endif
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const actionFilter = document.getElementById('action-filter');
    const dateFilter = document.getElementById('date-filter');
    
    function filterActivityLogs() {
        const action = actionFilter.value;
        const dateRange = dateFilter.value;
        
        // Here you would typically make an AJAX request to filter the logs
        // For now, we'll just show a message
        console.log('Filtering by action:', action, 'and date range:', dateRange);
        
        // You can implement AJAX filtering here
        // fetch(`/profile/activity-logs?action=${action}&date_range=${dateRange}`)
        //     .then(response => response.json())
        //     .then(data => updateTable(data));
    }
    
    actionFilter.addEventListener('change', filterActivityLogs);
    dateFilter.addEventListener('change', filterActivityLogs);
});
</script> 