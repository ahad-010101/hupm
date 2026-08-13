{{-- Placeholder for the WP-18 public pages. The route and navigation are real
     so the WP-05 shell can be tested; only the body is pending. --}}
@extends('public.layout')

@section('title', $heading.' — '.$company['name'])

@section('content')
    <h1 class="text-2xl font-semibold">{{ $heading }}</h1>
    <p class="mt-2 max-w-prose text-gray-700">
        This page is scheduled for WP-18, which builds the eight public pages against
        UI §1. The layout, navigation and footer around it are complete.
    </p>
@endsection
