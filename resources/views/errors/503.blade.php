{{-- Shown during a deployment (php artisan down). --}}
@extends('public.layout')

@section('title', 'Back shortly')

@section('content')
    <h1 class="text-2xl font-semibold">We will be back shortly</h1>
    <p class="mt-2 text-gray-700">
        The system is briefly offline for maintenance. Please try again in a few
        minutes — nothing you have submitted has been affected.
    </p>
@endsection
