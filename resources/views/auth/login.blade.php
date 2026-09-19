@extends('layouts.app')

@section('title', 'Login - Rihla Travels')
@section('description', 'Login for Rihla Travels management system')

@section('content')
<div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-50">


    <div class="mb-8">
        <a href="{{ route('home') }}" class="flex items-center focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 rounded">
            <x-brand-logo class="h-20 w-auto" />
        </a>
    </div>

    <div class="w-full sm:max-w-md px-6 py-8 bg-white shadow-soft overflow-hidden sm:rounded-2xl">
        <h2 class="text-3xl font-bold text-center mb-8 text-ink">{{ __('Login') }}</h2>
        
        <!-- Session Status -->
        @if (session('status'))
            <div class="mb-6 p-4 text-sm text-success-dark bg-success/10 border border-success/40 rounded-lg">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-6">
            @csrf

            <!-- Email Address -->
            <div>
                <label for="email" class="block text-sm font-medium text-ink mb-2">{{ __('Email Address') }}</label>
                <input id="email" 
                       class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-wine-500 transition-colors" 
                       type="email" 
                       name="email" 
                       value="{{ old('email') }}" 
                       required 
                       autofocus
                       placeholder="{{ __('Enter your email') }}">
                @error('email')
                    <p class="mt-2 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            <!-- Password -->
            <div>
                <label for="password" class="block text-sm font-medium text-ink mb-2">{{ __('Password') }}</label>
                <input id="password" 
                       class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-wine-500 transition-colors" 
                       type="password" 
                       name="password" 
                       required
                       placeholder="{{ __('Enter your password') }}">
                @error('password')
                    <p class="mt-2 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            <!-- Remember Me -->
            <div class="flex items-center">
                <input id="remember" 
                       type="checkbox" 
                       class="h-4 w-4 text-wine-500 focus:ring-wine-500 border-gray-300 rounded" 
                       name="remember">
                <label for="remember" class="ml-2 block text-sm text-ink">
                    {{ __('Remember me') }}
                </label>
            </div>

            <div class="flex items-center justify-between pt-4">
                @if (Route::has('password.request'))
                    <a class="text-sm text-wine-500 hover:text-wine-600 transition-colors" 
                       href="{{ route('password.request') }}">
                        {{ __('Forgot your password?') }}
                    </a>
                @endif

                <button type="submit" 
                        class="btn-primary px-8 py-3">
                    {{ __('Log in') }}
                </button>
            </div>
        </form>

        <!-- Back to Home -->
        <div class="mt-8 pt-6 border-t border-gray-200 text-center">
            <a href="{{ route('home') }}" 
               class="text-sm text-ink hover:text-wine-500 transition-colors">
                {{ __('← Back to Home') }}
            </a>
        </div>
    </div>

    <!-- Fallback content if JavaScript fails -->
    <noscript>
        <div class="mt-8 p-4 bg-error/10 border border-error/40 text-error-dark rounded-lg">
            <p class="font-semibold">JavaScript Required</p>
            <p>This login page requires JavaScript to function properly. Please enable JavaScript in your browser.</p>
        </div>
    </noscript>
</div>

<!-- Debug Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Login page DOM loaded');
    
    // Check if CSS is loaded
    const styles = getComputedStyle(document.body);
    console.log('CSS loaded:', styles.fontFamily !== 'serif');
    
    // Check if Tailwind classes are working
    const testElement = document.querySelector('.bg-gray-50');
    if (testElement) {
        const bgColor = getComputedStyle(testElement).backgroundColor;
        console.log('Tailwind classes working:', bgColor !== 'rgba(0, 0, 0, 0)');
    }
    
    // Check form elements
    const form = document.querySelector('form');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    
    console.log('Form elements found:', {
        form: !!form,
        email: !!emailInput,
        password: !!passwordInput
    });
});
</script>
@endsection
