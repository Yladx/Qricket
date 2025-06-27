<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
        </p>
    </header>

    <x-danger-button
        onclick="confirmAccountDeletion()"
    >{{ __('Delete Account') }}</x-danger-button>

    <form method="post" action="{{ route('profile.destroy') }}" id="delete-account-form" class="hidden">
        @csrf
        @method('delete')
        <input type="password" name="password" id="delete-password" />
    </form>
</section>
